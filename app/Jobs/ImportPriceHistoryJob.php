<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ImportPriceHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutos
    public $tries = 1;

    protected $filePath;
    protected $userId;

    public function __construct(string $filePath, ?int $userId)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        try {
            $fullPath = Storage::disk('local')->path($this->filePath);
            
            if (!file_exists($fullPath)) {
                Log::error('File not found for import: ' . $fullPath);
                return;
            }

            // Detectar tipo de archivo
            $inputFileType = IOFactory::identify($fullPath);
            $reader = IOFactory::createReader($inputFileType);
            $reader->setReadDataOnly(true);

            // Cargar TODOS los productos en memoria de una vez (indexados por código)
            $productsCache = Product::all()->keyBy('code');
            
            Log::info('Products cached: ' . $productsCache->count());

            // Cargar historial existente con id y precio para poder actualizar
            $existingPriceHistory = ProductPriceHistory::select('id', 'product_id', 'effective_date', 'price')
                ->get()
                ->keyBy(fn($item) => $item->product_id . '_' . $item->effective_date->format('Y-m-d'));

            Log::info('Existing price history cached: ' . $existingPriceHistory->count());

            $currentYear = (int) now()->year;

            $yearColumns = [
                2025 => 'I',
                2024 => 'J',
                2023 => 'K',
                2022 => 'L',
            ];

            $stats = [
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            $batchInsertData = [];
            $batchSize = 500;
            $chunkSize = 1000;

            // Obtener total de filas con filtro ligero
            $countFilter = new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === 1 || $columnAddress === 'E';
                }
            };

            $reader->setReadFilter($countFilter);
            $tempSpreadsheet = $reader->load($fullPath);
            $totalRows = $tempSpreadsheet->getActiveSheet()->getHighestDataRow();
            $tempSpreadsheet->disconnectWorksheets();
            unset($tempSpreadsheet);
            gc_collect_cycles();

            Log::info("Import price history job: Total rows detected: {$totalRows}");

            // Procesar en chunks
            for ($startRow = 2; $startRow <= $totalRows; $startRow += $chunkSize) {
                $endRow = min($startRow + $chunkSize - 1, $totalRows);

                $chunkFilter = new class($startRow, $endRow) implements IReadFilter {
                    private int $startRow;
                    private int $endRow;

                    public function __construct(int $startRow, int $endRow)
                    {
                        $this->startRow = $startRow;
                        $this->endRow = $endRow;
                    }

                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        if ($row < $this->startRow || $row > $this->endRow) {
                            return false;
                        }
                        return in_array($columnAddress, ['E', 'I', 'J', 'K', 'L']);
                    }
                };

                $chunkReader = IOFactory::createReader($inputFileType);
                $chunkReader->setReadDataOnly(true);
                $chunkReader->setReadFilter($chunkFilter);

                $spreadsheet = $chunkReader->load($fullPath);
                $worksheet = $spreadsheet->getActiveSheet();

                for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
                    $stats['processed']++;

                    $productCode = $worksheet->getCell('E' . $rowIndex)->getValue();

                    if (empty($productCode)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $product = $productsCache->get($productCode);

                    if (!$product) {
                        if (count($stats['errors']) < 50) {
                            $stats['errors'][] = "Fila {$rowIndex}: Producto '{$productCode}' no encontrado";
                        }
                        $stats['skipped']++;
                        continue;
                    }

                    foreach ($yearColumns as $year => $column) {
                        $price = $worksheet->getCell($column . $rowIndex)->getValue();

                        if (empty($price) || !is_numeric($price)) {
                            continue;
                        }

                        $effectiveDate = Carbon::create($year, 1, 1, 0, 0, 0);
                        $cacheKey = $product->id . '_' . $effectiveDate->format('Y-m-d');

                        $existing = $existingPriceHistory->get($cacheKey);

                        if ($existing) {
                            // Ya existe: actualizar si el precio cambió (cualquier año)
                            if ((float) $existing->price != (float) $price) {
                                DB::table('product_price_history')
                                    ->where('id', $existing->id)
                                    ->update([
                                        'price' => $price,
                                        'updated_at' => now(),
                                    ]);
                                $existing->price = $price;
                                $stats['updated']++;

                                // Si es año en curso, también actualizar precio en products
                                if ($year == $currentYear) {
                                    DB::table('products')
                                        ->where('id', $product->id)
                                        ->update(['price' => $price, 'updated_at' => now()]);
                                    $product->price = $price;
                                }
                            }
                        } else {
                            // No existe: insertar para cualquier año
                            $batchInsertData[] = [
                                'product_id' => $product->id,
                                'price' => $price,
                                'effective_date' => $effectiveDate,
                                'created_by' => $this->userId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            $existingPriceHistory->put($cacheKey, (object) [
                                'id' => null,
                                'product_id' => $product->id,
                                'price' => $price,
                            ]);

                            // Si es año en curso, también actualizar precio en products
                            if ($year == $currentYear) {
                                DB::table('products')
                                    ->where('id', $product->id)
                                    ->update(['price' => $price, 'updated_at' => now()]);
                                $product->price = $price;
                            }
                        }
                    }

                    if (count($batchInsertData) >= $batchSize) {
                        DB::table('product_price_history')->insert($batchInsertData);
                        $stats['inserted'] += count($batchInsertData);
                        $batchInsertData = [];
                    }
                }

                // Liberar memoria del chunk
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $worksheet, $chunkReader);
                gc_collect_cycles();

                Log::info("Import job: Processed rows {$startRow}-{$endRow} of {$totalRows}, inserted {$stats['inserted']} records");
            }

            // Insertar registros restantes
            if (count($batchInsertData) > 0) {
                DB::table('product_price_history')->insert($batchInsertData);
                $stats['inserted'] += count($batchInsertData);
            }

            if (count($stats['errors']) >= 50) {
                $stats['errors'][] = "... y más errores (solo se muestran los primeros 50)";
            }

            Log::info('Price history import completed', $stats);

            // Limpiar archivo temporal
            Storage::disk('local')->delete($this->filePath);

        } catch (\Exception $e) {
            Log::error('Error in ImportPriceHistoryJob: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            // Limpiar archivo en caso de error
            if (Storage::disk('local')->exists($this->filePath)) {
                Storage::disk('local')->delete($this->filePath);
            }

            throw $e;
        }
    }
}

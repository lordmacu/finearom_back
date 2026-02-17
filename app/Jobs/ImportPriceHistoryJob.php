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
        ini_set('memory_limit', '1G');

        try {
            $fullPath = Storage::disk('local')->path($this->filePath);
            
            if (!file_exists($fullPath)) {
                Log::error('File not found for import: ' . $fullPath);
                return;
            }

            $spreadsheet = IOFactory::load($fullPath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Cargar TODOS los productos en memoria de una vez (indexados por código)
            $productsCache = Product::all()->keyBy('code');
            
            Log::info('Products cached: ' . $productsCache->count());

            // Cargar price history existente para evitar duplicados
            $existingPriceHistory = ProductPriceHistory::select('product_id', 'effective_date')
                ->get()
                ->map(fn($item) => $item->product_id . '_' . $item->effective_date->format('Y-m-d'))
                ->flip()
                ->toArray();

            Log::info('Existing price history cached: ' . count($existingPriceHistory));

            $yearColumns = [
                2025 => 8,
                2024 => 9,
                2023 => 10,
                2022 => 11,
            ];

            $stats = [
                'processed' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            $batchInsertData = [];
            $batchSize = 500; // Insertar cada 500 registros
            $rowIndex = 0;

            // Leer fila por fila (streaming) en lugar de cargar todo en memoria
            foreach ($worksheet->getRowIterator(2) as $row) { // Empezar desde fila 2 (skip header)
                $rowIndex++;
                $stats['processed']++;

                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }

                // Get product code (column E, index 4)
                $productCode = $rowData[4] ?? null;

                if (empty($productCode)) {
                    $stats['skipped']++;
                    continue;
                }

                // Búsqueda O(1) en caché
                $product = $productsCache->get($productCode);

                if (!$product) {
                    if (count($stats['errors']) < 50) {
                        $stats['errors'][] = "Fila " . ($rowIndex + 1) . ": Producto '{$productCode}' no encontrado";
                    }
                    $stats['skipped']++;
                    continue;
                }

                // Process each year's price
                foreach ($yearColumns as $year => $columnIndex) {
                    $price = $rowData[$columnIndex] ?? null;

                    if (empty($price) || !is_numeric($price)) {
                        continue;
                    }

                    $effectiveDate = Carbon::create($year, 1, 1, 0, 0, 0);
                    $cacheKey = $product->id . '_' . $effectiveDate->format('Y-m-d');

                    // Verificación O(1) en lugar de query
                    if (!isset($existingPriceHistory[$cacheKey])) {
                        $batchInsertData[] = [
                            'product_id' => $product->id,
                            'price' => $price,
                            'effective_date' => $effectiveDate,
                            'created_by' => $this->userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // Marcar como existente para evitar duplicados en el mismo archivo
                        $existingPriceHistory[$cacheKey] = true;
                    }
                }

                // Bulk insert cada N registros
                if (count($batchInsertData) >= $batchSize) {
                    DB::table('product_price_history')->insert($batchInsertData);
                    $stats['inserted'] += count($batchInsertData);
                    $batchInsertData = [];
                    
                    // Liberar memoria periódicamente
                    if ($rowIndex % 1000 === 0) {
                        gc_collect_cycles();
                        Log::info("Processed {$rowIndex} rows, inserted {$stats['inserted']} records");
                    }
                }
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

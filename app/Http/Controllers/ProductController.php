<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\ProductStoreRequest;
use App\Http\Requests\Product\ProductUpdateRequest;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductDiscount;
use App\Models\ProductPriceHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Carbon\Carbon;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:product list')->only(['index', 'export', 'search']);
        $this->middleware('can:product create')->only(['store', 'import']);
        $this->middleware('can:product edit')->only(['update']);
        $this->middleware('can:product delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        // Generar clave de caché única basada en los parámetros de la consulta
        $cacheKey = $this->generateProductsCacheKey($request);
        $cacheTimestampKey = $cacheKey . '.timestamp';

        // Verificar si el caché es válido comparando timestamps
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $lastModified = Cache::get('products.last_modified', 0);

        if ($cachedTimestamp && $cachedTimestamp < $lastModified) {
            // El caché está desactualizado, eliminarlo
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché permanente (solo se invalida manualmente en CRUD)
        $data = Cache::rememberForever($cacheKey, function () use ($request) {
            $query = Product::query()->with(['client:id,client_name,nit', 'discounts']);

            if ($search = $request->query('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('product_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            }

            if ($clientId = $request->query('client_id')) {
                $query->where('client_id', (int) $clientId);
            }

            if ($code = $request->query('code')) {
                $query->where('code', $code);
            }

            $allowedSorts = ['id', 'code', 'product_name', 'price', 'client_id'];
            $sortBy = $request->query('sort_by', 'id');
            $sortDir = $request->query('sort_direction', 'desc');
            if (! in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortBy, $sortDir);

            $paginate = filter_var($request->query('paginate', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $perPage = (int) ($request->query('per_page', 15));
            $perPage = max(1, min(500, $perPage));

            if ($paginate === false) {
                $items = $query->limit($perPage)->get();
                return [
                    'success' => true,
                    'data' => $items,
                    'meta' => [
                        'total' => $items->count(),
                        'paginate' => false,
                    ],
                ];
            }

            $products = $query->paginate($perPage);

            return [
                'success' => true,
                'data' => $products->items(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'paginate' => true,
                ],
            ];
        });

        // Guardar timestamp del caché si es la primera vez (permanente)
        if (!Cache::has($cacheTimestampKey)) {
            Cache::forever($cacheTimestampKey, now()->timestamp);
        }

        return response()->json($data);
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $discounts = $validated['discounts'] ?? [];
        unset($validated['discounts']);

        $product = Product::create($validated);
        $this->syncDiscounts($product, $discounts);

        $this->clearProductsCache();

        return response()->json([
            'success' => true,
            'message' => 'Producto creado',
            'data' => $product->load('discounts'),
        ], 201);
    }

    public function update(ProductUpdateRequest $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $validated = $request->validated();
        $discounts = $validated['discounts'] ?? [];
        unset($validated['discounts']);

        $product->update($validated);
        $this->syncDiscounts($product, $discounts);

        $this->clearProductsCache();

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado',
            'data' => $product->load('discounts'),
        ]);
    }

    public function destroy(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $product->delete();

        // Invalidar caché de productos
        $this->clearProductsCache();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado',
        ]);
    }

    /**
     * Búsqueda de productos para componentes Select/Autocomplete.
     * Retorna formato compatible con Select2: [{id, text}].
     */
    public function search(Request $request): JsonResponse
    {
        $search = $request->query('search', '');
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min(50, $limit));

        $cacheKey = "products.search." . md5($search . '|' . $limit);
        $cacheTimestampKey = "{$cacheKey}.timestamp";

        // Verificar si el caché es válido comparando timestamps
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $lastModified = Cache::get('products.last_modified', 0);

        if ($cachedTimestamp && $cachedTimestamp < $lastModified) {
            // El caché está desactualizado, eliminarlo
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché permanente (solo se invalida manualmente en CRUD)
        $products = Cache::rememberForever($cacheKey, function () use ($search, $limit) {
            $query = Product::query();

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('product_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('id', $search);
                });
            }

            return $query
                ->select('id', DB::raw('product_name as text'), 'code', 'price')
                ->limit($limit)
                ->get();
        });

        // Guardar timestamp del caché si es la primera vez (permanente)
        if (!Cache::has($cacheTimestampKey)) {
            Cache::forever($cacheTimestampKey, now()->timestamp);
        }

        return response()->json($products);
    }

    /**
     * Importa o actualiza productos desde un Excel (codigo, nombre, precio, nit|client_id).
     * Encabezados esperados (case-insensitive): code, product_name, price, nit (o client_id).
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt'],
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);

        $rows = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) {
            return response()->json(['success' => false, 'message' => 'Archivo sin datos'], 422);
        }

        // Primera fila como headers
        $headers = array_map(function ($val) {
            return strtolower(trim((string) $val));
        }, $rows[1] ?? []);

        $colIndex = array_flip($headers);
        $required = ['code', 'product_name', 'price'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $colIndex)) {
                return response()->json(['success' => false, 'message' => "Falta columna requerida: {$key}"], 422);
            }
        }

        $updated = 0;
        $created = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            for ($i = 2; $i <= count($rows); $i++) {
                $row = $rows[$i];
                if (! is_array($row)) {
                    continue;
                }

                $code = trim((string) ($row[$colIndex['code']] ?? ''));
                $name = trim((string) ($row[$colIndex['product_name']] ?? ''));
                $price = $row[$colIndex['price']] ?? 0;
                $clientId = null;

                if (isset($colIndex['client_id'])) {
                    $clientId = (int) ($row[$colIndex['client_id']] ?? 0);
                } elseif (isset($colIndex['nit'])) {
                    $nit = trim((string) ($row[$colIndex['nit']] ?? ''));
                    $clientId = Client::query()->where('nit', $nit)->value('id');
                }

                if ($code === '' || $name === '') {
                    $errors[] = ['row' => $i, 'message' => 'code/product_name vacio'];
                    continue;
                }

                if (! $clientId) {
                    $errors[] = ['row' => $i, 'message' => 'Cliente no encontrado'];
                    continue;
                }

                $product = Product::query()
                    ->where('code', $code)
                    ->where('client_id', $clientId)
                    ->first();

                if ($product) {
                    $product->update([
                        'product_name' => $name,
                        'price' => is_numeric($price) ? (float) $price : 0,
                        'client_id' => $clientId,
                    ]);
                    $updated++;
                } else {
                    Product::query()->create([
                        'code' => $code,
                        'product_name' => $name,
                        'price' => is_numeric($price) ? (float) $price : 0,
                        'client_id' => $clientId,
                    ]);
                    $created++;
                }
            }

            DB::commit();

            // Invalidar caché de productos si hubo cambios
            if ($created > 0 || $updated > 0) {
                $this->clearProductsCache();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Importacion procesada',
            'data' => [
                'updated' => $updated,
                'created' => $created,
                'errors' => $errors,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = Product::query()
            ->with('client:id,client_name,nit');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', (int) $clientId);
        }

        if ($code = $request->query('code')) {
            $query->where('code', $code);
        }

        $allowedSorts = ['id', 'code', 'product_name', 'price', 'client_id'];
        $sortBy = $request->query('sort_by', 'id');
        $sortDir = $request->query('sort_direction', 'desc');
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $callback = function () use ($query) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->fromArray([
                ['code', 'product_name', 'price', 'nit', 'client_name'],
            ], null, 'A1', true);

            $rowNumber = 2;
            $query->chunk(500, function ($products) use (&$rowNumber, $sheet) {
                foreach ($products as $product) {
                    $sheet->setCellValue("A{$rowNumber}", $product->code);
                    $sheet->setCellValue("B{$rowNumber}", $product->product_name);
                    $sheet->setCellValue("C{$rowNumber}", (float) $product->price);
                    $sheet->setCellValue("D{$rowNumber}", optional($product->client)->nit);
                    $sheet->setCellValue("E{$rowNumber}", optional($product->client)->client_name);
                    $rowNumber++;
                }
            });

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        };

        $fileName = 'products_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload($callback, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Sincroniza los descuentos de un producto (reemplaza los existentes).
     */
    private function syncDiscounts(Product $product, array $discounts): void
    {
        $product->discounts()->delete();

        foreach ($discounts as $discount) {
            $product->discounts()->create([
                'min_quantity' => $discount['min_quantity'],
                'discount_percentage' => $discount['discount_percentage'],
            ]);
        }
    }

    /**
     * Generar clave de caché única basada en los parámetros de la consulta
     */
    private function generateProductsCacheKey(Request $request): string
    {
        $params = [
            'search' => $request->query('search', ''),
            'client_id' => $request->query('client_id', ''),
            'code' => $request->query('code', ''),
            'sort_by' => $request->query('sort_by', 'id'),
            'sort_direction' => $request->query('sort_direction', 'desc'),
            'per_page' => $request->query('per_page', 15),
            'paginate' => $request->query('paginate', true),
            'page' => $request->query('page', 1),
        ];

        return 'products.index.' . md5(json_encode($params));
    }

    /**
     * Invalidar todas las claves de caché relacionadas con productos
     */
    private function clearProductsCache(): void
    {
        // Guardar un timestamp de invalidación para verificar la frescura del caché
        Cache::forever('products.last_modified', now()->timestamp);
    }

    /**
     * Método estático para invalidar caché desde otros controladores
     */
    public static function clearProductsCacheStatic(): void
    {
        Cache::forever('products.last_modified', now()->timestamp);
    }

    /**
     * Get price history for a product
     */
    public function priceHistory(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        $history = $product->priceHistory()
            ->with('creator:id,name')
            ->orderBy('effective_date', 'desc')
            ->get()
            ->map(function ($record) {
                return [
                    'id' => $record->id,
                    'price' => $record->price,
                    'effective_date' => $record->effective_date->format('Y-m-d H:i:s'),
                    'created_by' => $record->creator?->name ?? 'Sistema',
                    'created_at' => $record->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Import price history from Excel file
     * Optimized with chunked reading to avoid memory exhaustion
     */
    public function importPriceHistory(Request $request): JsonResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(600);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        try {
            $file = $request->file('file');
            $filePath = $file->getPathname();

            // Detectar tipo de archivo
            $inputFileType = IOFactory::identify($filePath);
            $reader = IOFactory::createReader($inputFileType);

            // CLAVE: Solo leer datos, no estilos ni formatos (ahorra mucha memoria)
            $reader->setReadDataOnly(true);

            // Cargar productos y historial existente en memoria (esto es pequeño)
            $productsCache = Product::all()->keyBy('code');

            // Cargar historial existente con id y precio para poder actualizar
            $existingPriceHistory = ProductPriceHistory::select('id', 'product_id', 'effective_date', 'price')
                ->get()
                ->keyBy(fn($item) => $item->product_id . '_' . $item->effective_date->format('Y-m-d'));

            $currentYear = (int) now()->year;

            $yearColumns = [
                2025 => 'I', // Columna I (índice 8)
                2024 => 'J', // Columna J (índice 9)
                2023 => 'K', // Columna K (índice 10)
                2022 => 'L', // Columna L (índice 11)
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
            $chunkSize = 1000; // Leer 1000 filas a la vez

            // Primero, obtener el total de filas sin cargar todo
            // Usamos un filtro que solo lee la columna E (código producto) para contar
            $countFilter = new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === 1 || $columnAddress === 'E';
                }
            };

            $reader->setReadFilter($countFilter);
            $tempSpreadsheet = $reader->load($filePath);
            $totalRows = $tempSpreadsheet->getActiveSheet()->getHighestDataRow();
            $tempSpreadsheet->disconnectWorksheets();
            unset($tempSpreadsheet);
            gc_collect_cycles();

            Log::info("Import price history: Total rows detected: {$totalRows}");

            // Procesar en chunks
            for ($startRow = 2; $startRow <= $totalRows; $startRow += $chunkSize) {
                $endRow = min($startRow + $chunkSize - 1, $totalRows);

                // Filtro para leer solo las columnas necesarias (E, I, J, K, L) y el rango de filas
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

                // Crear nuevo reader para cada chunk
                $chunkReader = IOFactory::createReader($inputFileType);
                $chunkReader->setReadDataOnly(true);
                $chunkReader->setReadFilter($chunkFilter);

                $spreadsheet = $chunkReader->load($filePath);
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
                                'created_by' => auth()->id(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            // Marcar en caché para evitar duplicados en el mismo archivo
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

                    // Bulk insert cada N registros
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

                Log::info("Import price history: Processed rows {$startRow}-{$endRow} of {$totalRows}");
            }

            // Insertar registros restantes
            if (count($batchInsertData) > 0) {
                DB::table('product_price_history')->insert($batchInsertData);
                $stats['inserted'] += count($batchInsertData);
            }

            if (count($stats['errors']) >= 50) {
                $stats['errors'][] = "... y más errores";
            }

            return response()->json([
                'success' => true,
                'message' => 'Importación completada',
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error importing price history: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al importar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import current year product prices from Excel file (BASE SISTEMA format)
     * Format: A=Code, B=Product Name, C=Price, D=Client NIT, E=Client Name, F=Operation
     * 
     * Logic per row:
     * - Find client by NIT (column D)
     * - Find product by code (column A) + client_id
     * - If product exists for that client: update price
     * - If product does NOT exist for that client: create it
     * - Also upserts product_price_history for the current year
     */
    public function importCurrentPrices(Request $request): JsonResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(600);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        try {
            $file = $request->file('file');
            $filePath = $file->getPathname();

            $inputFileType = IOFactory::identify($filePath);
            $reader = IOFactory::createReader($inputFileType);
            $reader->setReadDataOnly(true);

            // Cargar clientes indexados por NIT
            $clientsCache = Client::all()->keyBy('nit');

            // Cargar productos indexados por "code_clientId"
            $productsCache = Product::all()->keyBy(fn($p) => $p->code . '_' . $p->client_id);

            $currentYear = (int) now()->year;
            $effectiveDate = Carbon::create($currentYear, 1, 1, 0, 0, 0);

            // Cargar el ÚLTIMO precio en bitácora del año actual por producto
            // para saber si cambió y debe crear nuevo registro
            $latestPriceHistory = DB::table('product_price_history')
                ->select('product_id', DB::raw('MAX(id) as last_id'))
                ->whereYear('effective_date', $currentYear)
                ->groupBy('product_id')
                ->get()
                ->pluck('last_id', 'product_id');

            $lastPrices = collect();
            if ($latestPriceHistory->isNotEmpty()) {
                $lastPrices = DB::table('product_price_history')
                    ->whereIn('id', $latestPriceHistory->values())
                    ->get()
                    ->keyBy('product_id');
            }

            $stats = [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'history_inserted' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            $chunkSize = 1000;

            // Obtener total de filas
            $countFilter = new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === 1 || $columnAddress === 'A';
                }
            };
            $reader->setReadFilter($countFilter);
            $tempSpreadsheet = $reader->load($filePath);
            $totalRows = $tempSpreadsheet->getActiveSheet()->getHighestDataRow();
            $tempSpreadsheet->disconnectWorksheets();
            unset($tempSpreadsheet);
            gc_collect_cycles();

            Log::info("Import current prices: Total rows detected: {$totalRows}");

            $batchHistoryInsert = [];
            $batchSize = 500;

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
                        return in_array($columnAddress, ['A', 'B', 'C', 'D']);
                    }
                };

                $chunkReader = IOFactory::createReader($inputFileType);
                $chunkReader->setReadDataOnly(true);
                $chunkReader->setReadFilter($chunkFilter);

                $spreadsheet = $chunkReader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();

                for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
                    $stats['processed']++;

                    $code = $worksheet->getCell('A' . $rowIndex)->getValue();
                    $productName = $worksheet->getCell('B' . $rowIndex)->getValue();
                    $price = $worksheet->getCell('C' . $rowIndex)->getValue();
                    $clientNit = $worksheet->getCell('D' . $rowIndex)->getValue();

                    if (empty($code) || empty($price) || !is_numeric($price)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $code = trim((string) $code);
                    $price = floor((float) $price * 100) / 100; // Truncar a 2 decimales
                    $clientNit = trim((string) $clientNit);

                    if (empty($clientNit)) {
                        if (count($stats['errors']) < 50) {
                            $stats['errors'][] = "Fila {$rowIndex}: Sin NIT de cliente para producto '{$code}'";
                        }
                        $stats['skipped']++;
                        continue;
                    }

                    // Buscar cliente por NIT
                    $client = $clientsCache->get($clientNit);
                    if (!$client) {
                        if (count($stats['errors']) < 50) {
                            $stats['errors'][] = "Fila {$rowIndex}: Cliente con NIT '{$clientNit}' no encontrado";
                        }
                        $stats['skipped']++;
                        continue;
                    }

                    $cacheKey = $code . '_' . $client->id;
                    $product = $productsCache->get($cacheKey);

                    if ($product) {
                        // Producto existe para este cliente: actualizar precio
                        if ((float) $product->price != $price) {
                            DB::table('products')
                                ->where('id', $product->id)
                                ->update(['price' => $price, 'updated_at' => now()]);
                            $product->price = $price;
                            $stats['updated']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        // Producto NO existe para este cliente: crearlo
                        $newProduct = Product::create([
                            'code' => $code,
                            'product_name' => $productName ?: $code,
                            'price' => $price,
                            'client_id' => $client->id,
                        ]);

                        // Agregar al caché para evitar duplicados en el mismo archivo
                        $productsCache->put($cacheKey, $newProduct);
                        $product = $newProduct;
                        $stats['created']++;
                    }

                    // Bitácora: crear nuevo registro si el precio cambió o no existe
                    $lastHistory = $lastPrices->get($product->id);
                    $lastPrice = $lastHistory ? (float) $lastHistory->price : null;

                    if ($lastPrice === null || $lastPrice != $price) {
                        // No existe registro o el precio cambió: insertar nuevo
                        $batchHistoryInsert[] = [
                            'product_id' => $product->id,
                            'price' => $price,
                            'effective_date' => now(),
                            'created_by' => auth()->id(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // Actualizar caché para evitar duplicados en el mismo archivo
                        $lastPrices->put($product->id, (object) [
                            'product_id' => $product->id,
                            'price' => $price,
                        ]);

                        if (count($batchHistoryInsert) >= $batchSize) {
                            DB::table('product_price_history')->insert($batchHistoryInsert);
                            $stats['history_inserted'] += count($batchHistoryInsert);
                            $batchHistoryInsert = [];
                        }
                    }
                }

                // Liberar memoria del chunk
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $worksheet, $chunkReader);
                gc_collect_cycles();

                Log::info("Import current prices: Processed rows {$startRow}-{$endRow} of {$totalRows}");
            }

            // Insertar historial restante
            if (count($batchHistoryInsert) > 0) {
                DB::table('product_price_history')->insert($batchHistoryInsert);
                $stats['history_inserted'] += count($batchHistoryInsert);
            }

            if (count($stats['errors']) >= 50) {
                $stats['errors'][] = "... y más errores";
            }

            // Invalidar caché de productos
            Cache::put('products.last_modified', now()->timestamp);

            return response()->json([
                'success' => true,
                'message' => 'Importación de precios actuales completada',
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error importing current prices: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al importar: ' . $e->getMessage(),
            ], 500);
        }
    }
}

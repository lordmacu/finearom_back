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
     * Ultra-optimized with cache and bulk insert
     */
    public function importPriceHistory(Request $request): JsonResponse
    {
        ini_set('memory_limit', '1G');
        set_time_limit(600);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            
            // *** OPTIMIZACIÓN 1: Cargar TODOS los productos en memoria (indexados por código) ***
            $productsCache = Product::all()->keyBy('code');
            
            // *** OPTIMIZACIÓN 2: Cargar price history existente para evitar duplicados ***
            $existingPriceHistory = ProductPriceHistory::select('product_id', 'effective_date')
                ->get()
                ->map(fn($item) => $item->product_id . '_' . $item->effective_date->format('Y-m-d'))
                ->flip()
                ->toArray();

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
            $batchSize = 500;
            $rowIndex = 0;

            // *** OPTIMIZACIÓN 3: Leer fila por fila (streaming) ***
            foreach ($worksheet->getRowIterator(2) as $row) {
                $rowIndex++;
                $stats['processed']++;

                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }

                $productCode = $rowData[4] ?? null;

                if (empty($productCode)) {
                    $stats['skipped']++;
                    continue;
                }

                // *** OPTIMIZACIÓN 4: Búsqueda O(1) en caché en lugar de query ***
                $product = $productsCache->get($productCode);

                if (!$product) {
                    if (count($stats['errors']) < 50) {
                        $stats['errors'][] = "Fila " . ($rowIndex + 1) . " : Producto '{$productCode}' no encontrado";
                    }
                    $stats['skipped']++;
                    continue;
                }

                foreach ($yearColumns as $year => $columnIndex) {
                    $price = $rowData[$columnIndex] ?? null;

                    if (empty($price) || !is_numeric($price)) {
                        continue;
                    }

                    $effectiveDate = Carbon::create($year, 1, 1, 0, 0, 0);
                    $cacheKey = $product->id . '_' . $effectiveDate->format('Y-m-d');

                    // *** OPTIMIZACIÓN 5: Verificación O(1) en memoria ***
                    if (!isset($existingPriceHistory[$cacheKey])) {
                        $batchInsertData[] = [
                            'product_id' => $product->id,
                            'price' => $price,
                            'effective_date' => $effectiveDate,
                            'created_by' => auth()->id(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $existingPriceHistory[$cacheKey] = true;
                    }
                }

                // *** OPTIMIZACIÓN 6: Bulk insert cada 500 registros ***
                if (count($batchInsertData) >= $batchSize) {
                    DB::table('product_price_history')->insert($batchInsertData);
                    $stats['inserted'] += count($batchInsertData);
                    $batchInsertData = [];
                    
                    if ($rowIndex % 1000 === 0) {
                        gc_collect_cycles();
                    }
                }
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
}

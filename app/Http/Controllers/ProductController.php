<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\ProductStoreRequest;
use App\Http\Requests\Product\ProductUpdateRequest;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        $query = Product::query()->with('client:id,client_name,nit');

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
            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'total' => $items->count(),
                    'paginate' => false,
                ],
            ]);
        }

        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'paginate' => true,
            ],
        ]);
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Producto creado',
            'data' => $product,
        ], 201);
    }

    public function update(ProductUpdateRequest $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $product->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado',
            'data' => $product,
        ]);
    }

    public function destroy(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $product->delete();

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

        $query = Product::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        $products = $query
            ->select('id', DB::raw('product_name as text'), 'code', 'price')
            ->limit($limit)
            ->get();

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

                $code = trim((string) ($row[$colIndex['code'] + 1] ?? ''));
                $name = trim((string) ($row[$colIndex['product_name'] + 1] ?? ''));
                $price = $row[$colIndex['price'] + 1] ?? 0;
                $clientId = null;

                if (isset($colIndex['client_id'])) {
                    $clientId = (int) ($row[$colIndex['client_id'] + 1] ?? 0);
                } elseif (isset($colIndex['nit'])) {
                    $nit = trim((string) ($row[$colIndex['nit'] + 1] ?? ''));
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
                ['Code', 'Product Name', 'Price', 'Client NIT', 'Client Name', 'Operation'],
            ], null, 'A1', true);

            $rowNumber = 2;
            $query->chunk(500, function ($products) use (&$rowNumber, $sheet) {
                foreach ($products as $product) {
                    $sheet->setCellValue("A{$rowNumber}", $product->code);
                    $sheet->setCellValue("B{$rowNumber}", $product->product_name);
                    $sheet->setCellValue("C{$rowNumber}", (float) $product->price);
                    $sheet->setCellValue("D{$rowNumber}", optional($product->client)->nit);
                    $sheet->setCellValue("E{$rowNumber}", optional($product->client)->client_name);
                    $sheet->setCellValue("F{$rowNumber}", '');
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
}

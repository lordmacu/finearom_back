<?php

namespace App\Http\Controllers;

use App\Models\FineFragrance;
use App\Models\Fragrance;
use App\Models\FragranceFamily;
use App\Models\FragranceHouse;
use App\Models\ProductCategory;
use App\Models\ProjectProductType;
use App\Models\FinearomPriceHistory;
use App\Models\FinearomReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectCatalogController extends Controller
{
    private function perPage(Request $request, int $default = 30): int
    {
        return (int) min($request->query('per_page', $default), 999);
    }

    public function __construct()
    {
        $this->middleware('can:project list')->only([
            'fragrances', 'fineFragrances', 'houses', 'families',
            'finearomReferences', 'projectProductTypes', 'swissaromPriceHistory',
            'productCategories',
        ]);
        $this->middleware('can:project catalog manage')->only([
            'storeFragrance', 'updateFragrance', 'destroyFragrance',
            'storeFineFragrance', 'updateFineFragrance', 'destroyFineFragrance',
            'storeHouse', 'updateHouse', 'destroyHouse',
            'storeFamily', 'updateFamily', 'destroyFamily',
            'storeFinearomReference', 'updateFinearomReference', 'destroyFinearomReference',
            'storeProductType', 'updateProductType', 'destroyProductType',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Categorías de producto (accesible con project list)
    // ─────────────────────────────────────────────────────────────

    public function productCategories(): JsonResponse
    {
        $categories = ProductCategory::where('active', true)->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    // ─────────────────────────────────────────────────────────────
    // Fragancias Colección
    // ─────────────────────────────────────────────────────────────

    public function fragrances(Request $request): JsonResponse
    {
        $query = Fragrance::query();
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('referencia', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }
        $result = $query->orderBy('nombre')->paginate($this->perPage($request, 30));
        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => ['total' => $result->total(), 'per_page' => $result->perPage(), 'current_page' => $result->currentPage()],
        ]);
    }

    public function storeFragrance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:300',
            'referencia' => 'nullable|string|max:100',
            'codigo'     => 'nullable|string|max:100',
            'precio'     => 'nullable|numeric|min:0',
            'precio_usd' => 'nullable|numeric|min:0',
            'usos'       => 'nullable|array',
        ]);
        $fragrance = Fragrance::create($data);
        return response()->json(['success' => true, 'data' => $fragrance, 'message' => 'Fragancia creada'], 201);
    }

    public function updateFragrance(Request $request, Fragrance $fragrance): JsonResponse
    {
        $data = $request->validate([
            'nombre'     => 'nullable|string|max:300',
            'referencia' => 'nullable|string|max:100',
            'codigo'     => 'nullable|string|max:100',
            'precio'     => 'nullable|numeric|min:0',
            'precio_usd' => 'nullable|numeric|min:0',
            'usos'       => 'nullable|array',
        ]);
        $fragrance->update($data);
        return response()->json(['success' => true, 'data' => $fragrance->fresh(), 'message' => 'Fragancia actualizada']);
    }

    public function destroyFragrance(Fragrance $fragrance): JsonResponse
    {
        $fragrance->delete();
        return response()->json(['success' => true, 'message' => 'Fragancia eliminada']);
    }

    // ─────────────────────────────────────────────────────────────
    // Fine Fragrances
    // ─────────────────────────────────────────────────────────────

    public function fineFragrances(Request $request): JsonResponse
    {
        $query = FineFragrance::query()->with('house')->where('activo', true);
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contratipo', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%");
            });
        }
        if ($houseId = $request->query('house_id')) {
            $query->where('fine_fragrance_house_id', $houseId);
        }
        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }
        $result = $query->orderBy('contratipo')->paginate($this->perPage($request, 30));
        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => ['total' => $result->total(), 'per_page' => $result->perPage(), 'current_page' => $result->currentPage()],
        ]);
    }

    public function storeFineFragrance(Request $request): JsonResponse
    {
        // Redirigido al nuevo módulo /fine-fragrances — este endpoint ya no crea fragancias
        return response()->json(['message' => 'Usa el módulo /fine-fragrances para crear fragancias'], 422);
    }

    public function updateFineFragrance(Request $request, FineFragrance $fineFragrance): JsonResponse
    {
        return response()->json(['message' => 'Usa el módulo /fine-fragrances para editar fragancias'], 422);
    }

    public function destroyFineFragrance(FineFragrance $fineFragrance): JsonResponse
    {
        return response()->json(['message' => 'Usa el módulo /fine-fragrances para eliminar fragancias'], 422);
    }

    // ─────────────────────────────────────────────────────────────
    // Casas
    // ─────────────────────────────────────────────────────────────

    public function houses(Request $request): JsonResponse
    {
        $query = FragranceHouse::query();
        if ($search = $request->query('search')) {
            $query->where('nombre', 'like', "%{$search}%");
        }
        $result = $query->orderBy('nombre')->paginate($this->perPage($request, 50));
        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => ['total' => $result->total(), 'per_page' => $result->perPage(), 'current_page' => $result->currentPage()],
        ]);
    }

    public function storeHouse(Request $request): JsonResponse
    {
        $data = $request->validate(['nombre' => 'required|string|max:200|unique:fragrance_houses,nombre']);
        $house = FragranceHouse::create($data);
        return response()->json(['success' => true, 'data' => $house, 'message' => 'Casa creada'], 201);
    }

    public function updateHouse(Request $request, FragranceHouse $house): JsonResponse
    {
        $data = $request->validate(['nombre' => 'required|string|max:200|unique:fragrance_houses,nombre,' . $house->id]);
        $house->update($data);
        return response()->json(['success' => true, 'data' => $house->fresh(), 'message' => 'Casa actualizada']);
    }

    public function destroyHouse(FragranceHouse $house): JsonResponse
    {
        $house->delete();
        return response()->json(['success' => true, 'message' => 'Casa eliminada']);
    }

    // ─────────────────────────────────────────────────────────────
    // Familias
    // ─────────────────────────────────────────────────────────────

    public function families(Request $request): JsonResponse
    {
        $query = FragranceFamily::query()->with('casa');
        if ($search = $request->query('search')) {
            $query->where('nombre', 'like', "%{$search}%");
        }
        $result = $query->orderBy('nombre')->paginate($this->perPage($request, 50));
        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => ['total' => $result->total(), 'per_page' => $result->perPage(), 'current_page' => $result->currentPage()],
        ]);
    }

    public function storeFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:200',
            'familia_olfativa' => 'nullable|string|max:200',
            'nucleo'           => 'nullable|string|max:200',
            'genero'           => 'nullable|string|max:100',
            'casa_id'          => 'nullable|integer|exists:fragrance_houses,id',
        ]);
        $family = FragranceFamily::create($data);
        return response()->json(['success' => true, 'data' => $family->load('casa'), 'message' => 'Familia creada'], 201);
    }

    public function updateFamily(Request $request, FragranceFamily $family): JsonResponse
    {
        $data = $request->validate([
            'nombre'           => 'nullable|string|max:200',
            'familia_olfativa' => 'nullable|string|max:200',
            'nucleo'           => 'nullable|string|max:200',
            'genero'           => 'nullable|string|max:100',
            'casa_id'          => 'nullable|integer|exists:fragrance_houses,id',
        ]);
        $family->update($data);
        return response()->json(['success' => true, 'data' => $family->fresh('casa'), 'message' => 'Familia actualizada']);
    }

    public function destroyFamily(FragranceFamily $family): JsonResponse
    {
        $family->delete();
        return response()->json(['success' => true, 'message' => 'Familia eliminada']);
    }

    // ─────────────────────────────────────────────────────────────
    // Referencias Finearom
    // ─────────────────────────────────────────────────────────────

    public function finearomReferences(Request $request): JsonResponse
    {
        $query = FinearomReference::query();
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }
        $result = $query->orderBy('codigo')->paginate($this->perPage($request, 30));
        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => ['total' => $result->total(), 'per_page' => $result->perPage(), 'current_page' => $result->currentPage()],
        ]);
    }

    public function storeFinearomReference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:100|unique:finearom_references,codigo',
            'nombre' => 'required|string|max:300',
            'precio' => 'nullable|numeric|min:0',
        ]);
        $ref = FinearomReference::create($data);
        return response()->json(['success' => true, 'data' => $ref, 'message' => 'Referencia creada'], 201);
    }

    public function updateFinearomReference(Request $request, FinearomReference $swissaromReference): JsonResponse
    {
        $data = $request->validate([
            'codigo' => 'nullable|string|max:100|unique:finearom_references,codigo,' . $swissaromReference->id,
            'nombre' => 'nullable|string|max:300',
            'precio' => 'nullable|numeric|min:0',
        ]);

        // Registrar historial si el precio cambia
        if (isset($data['precio']) && (float) $data['precio'] !== (float) $swissaromReference->precio) {
            FinearomPriceHistory::create([
                'swissarom_reference_id' => $swissaromReference->id,
                'precio_anterior'        => $swissaromReference->precio,
                'precio_nuevo'           => $data['precio'],
                'changed_by'             => auth()->user()?->name,
            ]);
        }

        $swissaromReference->update($data);
        return response()->json(['success' => true, 'data' => $swissaromReference->fresh(), 'message' => 'Referencia actualizada']);
    }

    public function swissaromPriceHistory(FinearomReference $swissaromReference): JsonResponse
    {
        $history = $swissaromReference->priceHistory()->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'data' => $history]);
    }

    public function destroyFinearomReference(FinearomReference $swissaromReference): JsonResponse
    {
        $swissaromReference->delete();
        return response()->json(['success' => true, 'message' => 'Referencia eliminada']);
    }

    // ─────────────────────────────────────────────────────────────
    // Tipos de Producto
    // ─────────────────────────────────────────────────────────────

    public function projectProductTypes(Request $request): JsonResponse
    {
        $query = ProjectProductType::query();
        if ($search = $request->query('search')) {
            $query->where('nombre', 'like', "%{$search}%");
        }
        $result = $query->orderBy('nombre')->paginate($this->perPage($request, 50));
        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => ['total' => $result->total(), 'per_page' => $result->perPage(), 'current_page' => $result->currentPage()],
        ]);
    }

    public function storeProductType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:200',
            'categoria' => 'nullable|string|max:100',
        ]);
        $pt = ProjectProductType::create($data);
        return response()->json(['success' => true, 'data' => $pt, 'message' => 'Tipo de producto creado'], 201);
    }

    public function updateProductType(Request $request, ProjectProductType $projectProductType): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => 'nullable|string|max:200',
            'categoria' => 'nullable|string|max:100',
        ]);
        $projectProductType->update($data);
        return response()->json(['success' => true, 'data' => $projectProductType->fresh(), 'message' => 'Tipo actualizado']);
    }

    public function destroyProductType(ProjectProductType $projectProductType): JsonResponse
    {
        $projectProductType->delete();
        return response()->json(['success' => true, 'message' => 'Tipo eliminado']);
    }
}

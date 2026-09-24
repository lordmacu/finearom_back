<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectCatalogItem\ProjectCatalogItemRequest;
use App\Models\ProjectCatalogItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Administración de los catálogos de diseños de etiqueta y pirámides. Mismo
 * permiso que el catálogo de envases.
 */
class ProjectCatalogItemAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:envelope type manage');
    }

    public function index(Request $request, string $tipo): JsonResponse
    {
        abort_unless(array_key_exists($tipo, ProjectCatalogItem::TIPOS), 404);

        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $items = ProjectCatalogItem::tipo($tipo)
            ->buscar($request->query('search'))
            ->orderBy('category')->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    public function store(ProjectCatalogItemRequest $request): JsonResponse
    {
        $data = $request->safe()->except('photo');
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $this->guardarFoto($request->file('photo'));
        }

        $item = ProjectCatalogItem::create($data);

        return response()->json(['success' => true, 'data' => $item, 'message' => 'Ítem creado'], 201);
    }

    public function update(ProjectCatalogItemRequest $request, ProjectCatalogItem $item): JsonResponse
    {
        $data = $request->safe()->except('photo');
        if ($request->hasFile('photo')) {
            $this->borrarFoto($item);
            $data['photo_path'] = $this->guardarFoto($request->file('photo'));
        }

        $item->update($data);

        return response()->json(['success' => true, 'data' => $item->fresh(), 'message' => 'Ítem actualizado']);
    }

    public function destroy(ProjectCatalogItem $item): JsonResponse
    {
        $this->borrarFoto($item);
        $item->delete();

        return response()->json(['success' => true, 'message' => 'Ítem eliminado']);
    }

    private function guardarFoto(UploadedFile $foto): string
    {
        return $foto->storeAs('catalog-photos', Str::uuid() . '.' . $foto->getClientOriginalExtension(), 'local');
    }

    private function borrarFoto(ProjectCatalogItem $item): void
    {
        if ($item->photo_path) {
            Storage::disk('local')->delete($item->photo_path);
        }
    }
}

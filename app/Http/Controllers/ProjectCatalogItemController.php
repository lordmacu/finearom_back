<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectCatalogItem\ProjectCatalogSelectionRequest;
use App\Models\Project;
use App\Models\ProjectCatalogItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Catálogos de diseños de etiqueta y pirámides vistos desde el proyecto:
 * buscar ítems activos (como "Buscar envase"), su foto y la selección del
 * proyecto. La administración vive en ProjectCatalogItemAdminController.
 */
class ProjectCatalogItemController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project list')->only(['index', 'photo']);
        $this->middleware('can:project edit')->only(['sync']);
    }

    public function index(Request $request, string $tipo): JsonResponse
    {
        abort_unless(array_key_exists($tipo, ProjectCatalogItem::TIPOS), 404);

        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $items = ProjectCatalogItem::tipo($tipo)->where('active', true)
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

    /** La foto vive en el disco `local` (privado): solo sale por aquí. */
    public function photo(ProjectCatalogItem $item): BinaryFileResponse
    {
        abort_if(!$item->photo_path || !Storage::disk('local')->exists($item->photo_path), 404, 'El ítem no tiene foto');

        return response()->file(Storage::disk('local')->path($item->photo_path));
    }

    /** Reemplaza los ítems elegidos de un catálogo en el proyecto (no toca los del otro). */
    public function sync(ProjectCatalogSelectionRequest $request, Project $project, string $tipo): JsonResponse
    {
        abort_unless(array_key_exists($tipo, ProjectCatalogItem::TIPOS), 404);

        $validos = ProjectCatalogItem::tipo($tipo)->whereIn('id', $request->validated('item_ids'))->pluck('id');
        $otros   = $project->catalogItems()->where('tipo', '!=', $tipo)->pluck('project_catalog_items.id');
        $project->catalogItems()->sync($otros->merge($validos)->all());

        return response()->json([
            'success' => true,
            'data'    => $project->catalogItems()->where('tipo', $tipo)->get(),
            'message' => 'Selección actualizada',
        ]);
    }
}

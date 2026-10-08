<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectCatalogItem\ProjectCatalogFolderRequest;
use App\Models\ProjectCatalogFolder;
use App\Models\ProjectCatalogItem;
use Illuminate\Http\JsonResponse;

/**
 * Carpetas virtuales de los catálogos de Marketing: crear, renombrar, mover
 * (cambiar `parent_id`) y eliminar. Mismo permiso que el resto del catálogo.
 */
class ProjectCatalogFolderAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:envelope type manage');
    }

    /** Todas las carpetas de un catálogo con su ruta completa (para elegir destino al mover). */
    public function index(string $tipo): JsonResponse
    {
        abort_unless(array_key_exists($tipo, ProjectCatalogItem::TIPOS), 404);

        $carpetas = ProjectCatalogFolder::tipo($tipo)->orderBy('name')->get()->keyBy('id');
        $ruta = function (ProjectCatalogFolder $f) use ($carpetas) {
            $partes = [];
            for ($actual = $f; $actual; $actual = $carpetas->get($actual->parent_id)) {
                array_unshift($partes, $actual->name);
            }

            return implode(' / ', $partes);
        };

        $data = $carpetas->map(fn ($f) => ['id' => $f->id, 'parent_id' => $f->parent_id, 'name' => $f->name, 'path' => $ruta($f)])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(ProjectCatalogFolderRequest $request): JsonResponse
    {
        $folder = ProjectCatalogFolder::create($request->validated());

        return response()->json(['success' => true, 'data' => $folder, 'message' => 'Carpeta creada'], 201);
    }

    public function update(ProjectCatalogFolderRequest $request, ProjectCatalogFolder $folder): JsonResponse
    {
        $folder->update($request->validated());

        return response()->json(['success' => true, 'data' => $folder->fresh(), 'message' => 'Carpeta actualizada']);
    }

    public function destroy(ProjectCatalogFolder $folder): JsonResponse
    {
        if ($folder->children()->exists() || $folder->items()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'La carpeta no está vacía: mueve o elimina sus subcarpetas e imágenes primero.',
            ], 422);
        }

        $folder->delete();

        return response()->json(['success' => true, 'message' => 'Carpeta eliminada']);
    }
}

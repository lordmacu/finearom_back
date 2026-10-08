<?php

namespace App\Services;

use App\Models\ProjectCatalogFolder;
use App\Models\ProjectCatalogItem;
use Illuminate\Http\Request;

/**
 * Navegación por carpetas de los catálogos de Marketing (envases, diseños de
 * etiqueta y pirámides). La misma lógica sirve a la administración (ve todo) y
 * al proyecto (solo ítems activos).
 */
class CatalogBrowser
{
    public function browse(Request $request, string $tipo, bool $soloActivos): array
    {
        $folderId = $request->query('folder_id');
        $search   = trim((string) $request->query('search', ''));
        $perPage  = max(1, min(100, (int) $request->query('per_page', 24)));

        $folder = $folderId
            ? ProjectCatalogFolder::tipo($tipo)->findOrFail($folderId)
            : null;

        $items = ProjectCatalogItem::tipo($tipo)
            ->when($soloActivos, fn ($q) => $q->where('active', true))
            ->when(
                $search !== '',
                // Buscando se mira todo el catálogo, sin importar la carpeta
                fn ($q) => $q->buscar($search),
                fn ($q) => $q->where('folder_id', $folder?->id)
            )
            ->orderBy('category')->orderBy('name')
            ->paginate($perPage);

        $folders = $search !== ''
            ? collect()
            : ProjectCatalogFolder::tipo($tipo)->where('parent_id', $folder?->id)->orderBy('name')->get();

        return [
            'success'     => true,
            'folder'      => $folder,
            'breadcrumb'  => $folder?->ruta() ?? [],
            'folders'     => $folders,
            'data'        => $items->items(),
            'meta'        => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ];
    }
}

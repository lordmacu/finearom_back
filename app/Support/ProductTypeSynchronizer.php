<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Deja `project_product_types` con la lista de mercadeo sin perder nada:
 *
 *  1. A cada tipo heredado le asigna categoría y grupo. Los que son el mismo
 *     producto con otro nombre se renombran (conservan su id, así que los
 *     proyectos y las filas de time_applications siguen apuntando a ellos).
 *  2. Inserta los tipos de la lista que todavía no existan.
 *  3. Rellena `projects.product_category_id` desde la categoría de su tipo,
 *     sin tocar los proyectos que ya tienen categoría.
 *
 * Es idempotente: correrlo dos veces no duplica ni revierte nada.
 */
class ProductTypeSynchronizer
{
    /** @var array<string, int> slug => id */
    private array $categorias = [];

    public function run(): void
    {
        $this->categorias = DB::table('product_categories')->pluck('id', 'slug')->all();

        DB::transaction(function () {
            $this->mapearLegacy();
            $this->insertarFaltantes();
            $this->rellenarCategoriaDeProyectos();
        });
    }

    private function mapearLegacy(): void
    {
        foreach (ProductTypeCatalog::legacy() as $legacy) {
            $fila = $this->buscarPorNombre($legacy['nombre']);

            if (! $fila) {
                continue; // ese heredado no existe en esta base
            }

            DB::table('project_product_types')->where('id', $fila->id)->update([
                'nombre'              => $legacy['fusionar_con'] ?? $fila->nombre,
                'product_category_id' => $legacy['categoria'] ? ($this->categorias[$legacy['categoria']] ?? null) : null,
                'grupo'               => $legacy['grupo'],
                'updated_at'          => now(),
            ]);
        }
    }

    private function insertarFaltantes(): void
    {
        foreach (ProductTypeCatalog::tipos() as $tipo) {
            $categoriaId = $this->categorias[$tipo['categoria']] ?? null;

            if (! $categoriaId) {
                continue; // la categoría no existe en esta base
            }

            $yaExiste = $this->buscarPorNombre($tipo['nombre'], $categoriaId);

            if ($yaExiste) {
                continue;
            }

            DB::table('project_product_types')->insert([
                'nombre'              => $tipo['nombre'],
                'product_category_id' => $categoriaId,
                'grupo'               => $tipo['grupo'],
                'active'              => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    private function rellenarCategoriaDeProyectos(): void
    {
        $tipos = DB::table('project_product_types')
            ->whereNotNull('product_category_id')
            ->pluck('product_category_id', 'id');

        foreach ($tipos as $tipoId => $categoriaId) {
            DB::table('projects')
                ->where('product_id', $tipoId)
                ->whereNull('product_category_id')
                ->update(['product_category_id' => $categoriaId]);
        }
    }

    /**
     * Busca por nombre normalizado. Con $categoriaId sólo mira dentro de esa
     * categoría, porque un mismo nombre puede existir en dos (p. ej. "Cera").
     */
    private function buscarPorNombre(string $nombre, ?int $categoriaId = null): ?object
    {
        $objetivo = ProductTypeCatalog::normalizar($nombre);

        $query = DB::table('project_product_types');

        if ($categoriaId !== null) {
            $query->where('product_category_id', $categoriaId);
        }

        foreach ($query->get() as $fila) {
            if (ProductTypeCatalog::normalizar($fila->nombre) === $objetivo) {
                return $fila;
            }
        }

        return null;
    }
}

<?php

namespace App\Services;

use App\Models\CorazonFormulaLine;
use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Models\RawMaterial;
use App\Models\ReferenceFormulaLine;
use Illuminate\Support\Facades\DB;

/**
 * Reemplaza las materias primas provisionales (códigos 320/321 marcados
 * `pendiente_equivalencia`) por su equivalente código 300 en todas las
 * fórmulas: productos terminados, corazones y referencias.
 *
 * Solo toca las que ya tienen `equivalente_id`. Si la fórmula ya tenía el
 * equivalente, los porcentajes se suman (no puede repetirse la misma materia
 * prima en una fórmula). Después recalcula costos y deja la provisional
 * inactiva y sin marca.
 */
class RawMaterialEquivalenciaService
{
    /** @return array{reemplazadas: int, lineas_productos: int, lineas_corazones: int, lineas_referencias: int, productos_recalculados: int, corazones_recalculados: int} */
    public function reemplazar(): array
    {
        return DB::transaction(function () {
            $provisionales = RawMaterial::where('pendiente_equivalencia', true)->whereNotNull('equivalente_id')->get();

            $r = ['reemplazadas' => 0, 'lineas_productos' => 0, 'lineas_corazones' => 0, 'lineas_referencias' => 0,
                  'productos_recalculados' => 0, 'corazones_recalculados' => 0];
            $productos = collect();
            $corazones = collect();

            foreach ($provisionales as $prov) {
                $equiv = $prov->equivalente_id;

                $productos = $productos->merge(ProductoFormulaLine::where('raw_material_id', $prov->id)->pluck('producto_terminado_id'));
                $r['lineas_productos'] += $this->moverLineas(ProductoFormulaLine::class, 'producto_terminado_id', $prov->id, $equiv);

                $corazones = $corazones->merge(CorazonFormulaLine::where('raw_material_id', $prov->id)->pluck('corazon_id'));
                $r['lineas_corazones'] += $this->moverLineas(CorazonFormulaLine::class, 'corazon_id', $prov->id, $equiv);

                $r['lineas_referencias'] += $this->moverLineas(ReferenceFormulaLine::class, 'finearom_reference_id', $prov->id, $equiv);

                $prov->update(['pendiente_equivalencia' => false, 'activo' => false]);
                $r['reemplazadas']++;
            }

            // Costos: primero corazones (un producto puede usarlos), luego productos
            // Los corazones no calculan costo por ahora (config custom.corazones_calcular_costos)
            $costosCorazones = (bool) config('custom.corazones_calcular_costos', false);
            foreach ($costosCorazones ? $corazones->unique() : [] as $id) {
                $corazon = RawMaterial::find($id);
                if ($corazon) {
                    $corazon->update(['costo_unitario' => $this->costo(CorazonFormulaLine::where('corazon_id', $id)->with('rawMaterial')->get())]);
                    $productos = $productos->merge(ProductoFormulaLine::where('raw_material_id', $id)->pluck('producto_terminado_id'));
                    $r['corazones_recalculados']++;
                }
            }
            foreach ($productos->unique() as $id) {
                $producto = ProductoTerminado::find($id);
                if ($producto) {
                    $producto->update(['costo_unitario' => $this->costo(ProductoFormulaLine::where('producto_terminado_id', $id)->with('rawMaterial')->get())]);
                    $r['productos_recalculados']++;
                }
            }

            return $r;
        });
    }

    /** Cambia el ingrediente de las líneas; si el dueño ya tenía el equivalente, suma el porcentaje. */
    private function moverLineas(string $modelo, string $duenoCol, int $de, int $a): int
    {
        $movidas = 0;
        foreach ($modelo::where('raw_material_id', $de)->get() as $linea) {
            $existente = $modelo::where($duenoCol, $linea->{$duenoCol})->where('raw_material_id', $a)->first();
            if ($existente) {
                $existente->update(['porcentaje' => (float) $existente->porcentaje + (float) $linea->porcentaje]);
                $linea->delete();
            } else {
                $linea->update(['raw_material_id' => $a]);
            }
            $movidas++;
        }

        return $movidas;
    }

    /** Mismo cálculo que las pantallas: Σ porcentaje × costo por kg del ingrediente. */
    private function costo($lineas): float
    {
        $total = 0.0;
        foreach ($lineas as $l) {
            $rm = $l->rawMaterial;
            if (!$rm) {
                continue;
            }
            $factor = in_array($rm->unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0;
            $total += ((float) $l->porcentaje / 100) * ((float) $rm->costo_unitario / $factor);
        }

        return round($total, 4);
    }
}

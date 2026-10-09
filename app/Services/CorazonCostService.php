<?php

namespace App\Services;

use App\Models\CorazonFormulaLine;
use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Models\RawMaterial;

/**
 * Costo de un corazón = Σ (porcentaje/100) × costo por kg de cada ingrediente.
 * Un ingrediente puede ser una materia prima o OTRO corazón, así que cuando el
 * costo de un corazón cambia se recalculan también los corazones que lo usan
 * (hacia arriba) y los productos terminados que lo usan.
 */
class CorazonCostService
{
    /** Profundidad máxima al subir por corazones padre (los ciclos se impiden al crear la línea). */
    private const MAX_NIVELES = 20;

    public function costoKg(RawMaterial $rm): float
    {
        $factor = in_array($rm->unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0;

        return (float) $rm->costo_unitario / $factor;
    }

    /** Costo del corazón según su fórmula actual. */
    public function calcular(RawMaterial $corazon): float
    {
        $total = 0.0;
        foreach (CorazonFormulaLine::where('corazon_id', $corazon->id)->with('rawMaterial')->get() as $line) {
            if ($line->rawMaterial) {
                $total += ((float) $line->porcentaje / 100) * $this->costoKg($line->rawMaterial);
            }
        }

        return round($total, 4);
    }

    /** El cálculo automático está apagado por ahora (config custom.corazones_calcular_costos). */
    public function activo(): bool
    {
        return (bool) config('custom.corazones_calcular_costos', false);
    }

    /** Guarda el costo del corazón y propaga el cambio a productos y corazones padre. */
    public function recalcular(RawMaterial $corazon, int $nivel = 0): void
    {
        if (!$this->activo()) {
            return;
        }

        $corazon->update(['costo_unitario' => $this->calcular($corazon)]);

        $this->recalcularProductos($corazon);

        if ($nivel >= self::MAX_NIVELES) {
            return;
        }
        $padres = CorazonFormulaLine::where('raw_material_id', $corazon->id)->pluck('corazon_id')->unique();
        foreach ($padres as $padreId) {
            $padre = RawMaterial::find($padreId);
            if ($padre) {
                $this->recalcular($padre, $nivel + 1);
            }
        }
    }

    private function recalcularProductos(RawMaterial $corazon): void
    {
        $ids = ProductoFormulaLine::where('raw_material_id', $corazon->id)->pluck('producto_terminado_id')->unique();
        foreach ($ids as $productoId) {
            $producto = ProductoTerminado::find($productoId);
            if (!$producto) {
                continue;
            }
            $total = 0.0;
            foreach (ProductoFormulaLine::where('producto_terminado_id', $productoId)->with('rawMaterial')->get() as $line) {
                if ($line->rawMaterial) {
                    $total += ((float) $line->porcentaje / 100) * $this->costoKg($line->rawMaterial);
                }
            }
            $producto->update(['costo_unitario' => round($total, 4)]);
        }
    }

    /**
     * ¿Agregar $ingredienteId a la fórmula de $corazonId crearía un ciclo?
     * Es decir: el ingrediente es el propio corazón, o ya lo contiene (en cualquier nivel).
     */
    public function crearCiclo(int $corazonId, int $ingredienteId): bool
    {
        if ($corazonId === $ingredienteId) {
            return true;
        }
        $pendientes = [$ingredienteId];
        $vistos = [];
        while ($pendientes) {
            $actual = array_pop($pendientes);
            if (isset($vistos[$actual])) {
                continue;
            }
            $vistos[$actual] = true;
            $hijos = CorazonFormulaLine::where('corazon_id', $actual)->pluck('raw_material_id');
            foreach ($hijos as $hijo) {
                if ((int) $hijo === $corazonId) {
                    return true;
                }
                $pendientes[] = (int) $hijo;
            }
        }

        return false;
    }
}

<?php

namespace App\Services;

use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;

/**
 * Costo de un producto terminado = Σ (porcentaje/100) × costo por kg de cada ingrediente.
 * Un ingrediente puede ser una materia prima, un corazón u otro producto terminado: cuando
 * el costo de un producto cambia se recalculan también los productos que lo contienen.
 */
class ProductoCostService
{
    private const MAX_NIVELES = 20;

    private function factor(?string $unidad): float
    {
        return in_array($unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0;
    }

    /** Costo por kg que aporta el ingrediente de una línea (0 si no tiene). */
    public function costoKgLinea(ProductoFormulaLine $line): float
    {
        if ($line->rawMaterial) {
            return (float) $line->rawMaterial->costo_unitario / $this->factor($line->rawMaterial->unidad);
        }
        if ($line->ingredienteProducto) {
            return (float) $line->ingredienteProducto->costo_unitario; // los productos se cuestan por kg
        }

        return 0.0;
    }

    public function calcular(ProductoTerminado $producto): float
    {
        $total = 0.0;
        $lineas = ProductoFormulaLine::where('producto_terminado_id', $producto->id)->with(['rawMaterial', 'ingredienteProducto'])->get();
        foreach ($lineas as $line) {
            $total += ((float) $line->porcentaje / 100) * $this->costoKgLinea($line);
        }

        return round($total, 4);
    }

    /** Guarda el costo del producto y lo propaga a los productos que lo usan como ingrediente. */
    public function recalcular(ProductoTerminado $producto, int $nivel = 0): void
    {
        $producto->update(['costo_unitario' => $this->calcular($producto)]);

        if ($nivel >= self::MAX_NIVELES) {
            return;
        }
        $padres = ProductoFormulaLine::where('ingrediente_producto_id', $producto->id)->pluck('producto_terminado_id')->unique();
        foreach ($padres as $padreId) {
            $padre = ProductoTerminado::find($padreId);
            if ($padre) {
                $this->recalcular($padre, $nivel + 1);
            }
        }
    }

    /**
     * ¿Agregar $ingredienteId a la fórmula de $productoId crearía un ciclo?
     * Es decir: es el propio producto, o el ingrediente ya lo contiene en cualquier nivel.
     */
    public function crearCiclo(int $productoId, int $ingredienteId): bool
    {
        if ($productoId === $ingredienteId) {
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
            foreach (ProductoFormulaLine::where('producto_terminado_id', $actual)->whereNotNull('ingrediente_producto_id')->pluck('ingrediente_producto_id') as $hijo) {
                if ((int) $hijo === $productoId) {
                    return true;
                }
                $pendientes[] = (int) $hijo;
            }
        }

        return false;
    }
}

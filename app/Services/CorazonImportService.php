<?php

namespace App\Services;

use App\Models\CorazonFormulaLine;
use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Models\RawMaterial;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa corazones (con su fórmula) desde un Excel con formato fijo:
 * una fila por ingrediente, el corazón se repite en cada fila.
 *
 * El formato es estricto: si los encabezados no son exactamente los de la
 * plantilla, el archivo se rechaza. Es todo o nada: con un solo error de datos
 * no se importa nada. Un corazón que ya existe se actualiza (su fórmula se
 * reemplaza); uno nuevo se crea. Queda activo solo si la fórmula suma 100%.
 */
class CorazonImportService
{
    public const HEADERS = ['CODIGO CORAZON', 'NOMBRE CORAZON', 'DESCRIPCION', 'CODIGO INGREDIENTE', 'PORCENTAJE'];
    public const MAX_ROWS = 5000;
    private const MAX_ERRORS = 200;

    /**
     * @return array{errors: array<int, array{fila: int|null, mensaje: string}>, resumen: ?array}
     */
    public function procesar(string $path, bool $dryRun): array
    {
        $parsed = $this->leer($path);
        if ($parsed['errors']) {
            return ['errors' => $parsed['errors'], 'resumen' => null];
        }

        $plan = $this->validar($parsed['filas']);
        if ($plan['errors']) {
            return ['errors' => $plan['errors'], 'resumen' => null];
        }

        if (!$dryRun) {
            DB::transaction(fn () => $this->aplicar($plan['corazones']));
        }

        return ['errors' => [], 'resumen' => $this->resumen($plan['corazones'])];
    }

    /** @return array{errors: array, filas: array} */
    private function leer(string $path): array
    {
        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (\Throwable) {
            return ['errors' => [['fila' => null, 'mensaje' => 'No se pudo leer el archivo. Usa la plantilla de ejemplo en formato .xlsx.']], 'filas' => []];
        }

        $encabezados = [];
        foreach (range(1, count(self::HEADERS) + 1) as $col) {
            $encabezados[] = mb_strtoupper(trim((string) $sheet->getCell([$col, 1])->getValue()));
        }
        $extra = array_pop($encabezados);
        if ($encabezados !== self::HEADERS || $extra !== '') {
            return ['errors' => [['fila' => 1, 'mensaje' => 'El formato no coincide con la plantilla. La fila 1 debe tener exactamente estas columnas, en este orden: '
                . implode(' | ', self::HEADERS) . '.']], 'filas' => []];
        }

        $ultima = $sheet->getHighestDataRow();
        if ($ultima - 1 > self::MAX_ROWS) {
            return ['errors' => [['fila' => null, 'mensaje' => 'El archivo tiene más de ' . self::MAX_ROWS . ' filas. Divídelo en varios archivos.']], 'filas' => []];
        }

        $filas = [];
        for ($r = 2; $r <= $ultima; $r++) {
            $valores = [];
            foreach (range(1, count(self::HEADERS)) as $col) {
                $valores[] = $this->texto($sheet->getCell([$col, $r])->getValue());
            }
            if (!array_filter($valores, fn ($v) => $v !== '')) {
                continue;
            }
            $filas[] = ['fila' => $r, 'codigo' => $valores[0], 'nombre' => $valores[1], 'descripcion' => $valores[2],
                        'ingrediente' => $valores[3], 'porcentaje' => $valores[4]];
        }

        if (!$filas) {
            return ['errors' => [['fila' => null, 'mensaje' => 'El archivo no tiene filas de datos.']], 'filas' => []];
        }

        return ['errors' => [], 'filas' => $filas];
    }

    /** Excel entrega 100764 como número: se devuelve sin ".0". */
    private function texto(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            return (string) (int) $valor;
        }

        return trim((string) $valor);
    }

    /** @return array{errors: array, corazones: array} */
    private function validar(array $filas): array
    {
        $errores = [];
        $error = function (int $fila, string $mensaje) use (&$errores) {
            $errores[] = ['fila' => $fila, 'mensaje' => $mensaje];
        };

        $existentes = RawMaterial::whereIn('codigo', array_unique(array_column($filas, 'codigo')))->get()->keyBy('codigo');
        $ingredientes = RawMaterial::whereIn('codigo', array_unique(array_column($filas, 'ingrediente')))->get()->keyBy('codigo');

        $corazones = [];
        foreach ($filas as $f) {
            $n = $f['fila'];
            $ok = true;

            if ($f['codigo'] === '' || mb_strlen($f['codigo']) > 100) {
                $error($n, 'CODIGO CORAZON es obligatorio (máximo 100 caracteres).');
                $ok = false;
            }
            if ($f['nombre'] === '' || mb_strlen($f['nombre']) > 255) {
                $error($n, 'NOMBRE CORAZON es obligatorio (máximo 255 caracteres).');
                $ok = false;
            }
            if (mb_strlen($f['descripcion']) > 2000) {
                $error($n, 'DESCRIPCION no puede superar 2000 caracteres.');
                $ok = false;
            }

            $ing = $ingredientes[$f['ingrediente']] ?? null;
            if ($f['ingrediente'] === '') {
                $error($n, 'CODIGO INGREDIENTE es obligatorio.');
                $ok = false;
            } elseif (!$ing) {
                $error($n, "El ingrediente {$f['ingrediente']} no existe en materias primas.");
                $ok = false;
            } elseif ($ing->tipo !== 'materia_prima') {
                $error($n, "El ingrediente {$f['ingrediente']} es un corazón: un corazón solo puede tener materias primas.");
                $ok = false;
            }

            $pct = $this->porcentaje($f['porcentaje']);
            if ($pct === null || $pct < 0.0001 || $pct > 100) {
                $error($n, "PORCENTAJE inválido ({$f['porcentaje']}): debe ser un número mayor a 0 y hasta 100.");
                $ok = false;
            }

            if ($f['codigo'] === '') {
                continue;
            }
            $existente = $existentes[$f['codigo']] ?? null;
            if ($existente && $existente->tipo !== 'corazon') {
                $error($n, "El código {$f['codigo']} ya existe como materia prima, no como corazón.");
                $ok = false;
            }

            $c = &$corazones[$f['codigo']];
            $c ??= ['codigo' => $f['codigo'], 'nombre' => $f['nombre'], 'descripcion' => '', 'existente' => $existente?->tipo === 'corazon' ? $existente : null, 'lineas' => []];
            if ($f['nombre'] !== '' && $f['nombre'] !== $c['nombre']) {
                $error($n, "El corazón {$f['codigo']} tiene nombres distintos en el archivo ('{$c['nombre']}' y '{$f['nombre']}').");
            }
            if ($c['descripcion'] === '' && $f['descripcion'] !== '') {
                $c['descripcion'] = $f['descripcion'];
            }
            if ($ok) {
                if (isset($c['lineas'][$ing->id])) {
                    $error($n, "El ingrediente {$f['ingrediente']} está repetido en el corazón {$f['codigo']}.");
                } else {
                    $c['lineas'][$ing->id] = ['ing' => $ing, 'porcentaje' => $pct];
                }
            }
            unset($c);
        }

        if (count($errores) > self::MAX_ERRORS) {
            $total = count($errores);
            $errores = array_slice($errores, 0, self::MAX_ERRORS);
            $errores[] = ['fila' => null, 'mensaje' => "Hay {$total} errores en total; se muestran los primeros " . self::MAX_ERRORS . '.'];
        }

        return ['errors' => $errores, 'corazones' => $corazones];
    }

    private function porcentaje(string $valor): ?float
    {
        $valor = str_replace(['%', ' '], '', $valor);
        $valor = str_replace(',', '.', $valor);

        return is_numeric($valor) ? (float) $valor : null;
    }

    private function aplicar(array $corazones): void
    {
        foreach ($corazones as $c) {
            $suma = array_sum(array_column($c['lineas'], 'porcentaje'));
            $costo = 0.0;
            foreach ($c['lineas'] as $l) {
                $costo += ($l['porcentaje'] / 100) * $this->costoKg($l['ing']);
            }
            $datos = ['nombre' => $c['nombre'], 'costo_unitario' => round($costo, 4), 'activo' => abs($suma - 100) < 0.01];
            if ($c['descripcion'] !== '') {
                $datos['descripcion'] = $c['descripcion'];
            }

            if ($c['existente']) {
                $corazon = $c['existente'];
                $corazon->update($datos);
                CorazonFormulaLine::where('corazon_id', $corazon->id)->delete();
            } else {
                $corazon = RawMaterial::create([...$datos, 'codigo' => $c['codigo'], 'tipo' => 'corazon', 'unidad' => 'kg']);
            }

            foreach ($c['lineas'] as $id => $l) {
                CorazonFormulaLine::create(['corazon_id' => $corazon->id, 'raw_material_id' => $id, 'porcentaje' => $l['porcentaje']]);
            }

            $this->recalcularProductos($corazon);
        }
    }

    private function costoKg(RawMaterial $rm): float
    {
        $factor = in_array($rm->unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0;

        return (float) $rm->costo_unitario / $factor;
    }

    /** Los productos terminados que usan este corazón cambian de costo. */
    private function recalcularProductos(RawMaterial $corazon): void
    {
        $ids = ProductoFormulaLine::where('raw_material_id', $corazon->id)->pluck('producto_terminado_id')->unique();
        foreach ($ids as $id) {
            $total = 0.0;
            foreach (ProductoFormulaLine::where('producto_terminado_id', $id)->with('rawMaterial')->get() as $l) {
                if ($l->rawMaterial) {
                    $total += ((float) $l->porcentaje / 100) * $this->costoKg($l->rawMaterial);
                }
            }
            ProductoTerminado::where('id', $id)->update(['costo_unitario' => round($total, 4)]);
        }
    }

    private function resumen(array $corazones): array
    {
        $creados = $actualizados = $activos = $conPendientes = 0;
        $detalle = [];
        foreach ($corazones as $c) {
            $suma = round(array_sum(array_column($c['lineas'], 'porcentaje')), 4);
            $activo = abs($suma - 100) < 0.01;
            $pendientes = count(array_filter($c['lineas'], fn ($l) => $l['ing']->pendiente_equivalencia));
            $c['existente'] ? $actualizados++ : $creados++;
            $activo && $activos++;
            $pendientes && $conPendientes++;
            $detalle[] = ['codigo' => $c['codigo'], 'nombre' => $c['nombre'], 'accion' => $c['existente'] ? 'actualizado' : 'creado',
                          'ingredientes' => count($c['lineas']), 'suma' => $suma, 'estado' => $activo ? 'activo' : 'borrador',
                          'pendientes_equivalencia' => $pendientes];
        }

        return ['creados' => $creados, 'actualizados' => $actualizados, 'activos' => $activos, 'borrador' => count($corazones) - $activos,
                'con_pendientes_equivalencia' => $conPendientes, 'corazones' => array_slice($detalle, 0, 100), 'total' => count($corazones)];
    }
}

<?php

namespace App\Services;

use App\Models\CorazonFormulaLine;
use App\Models\RawMaterial;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa corazones (con su fórmula) desde un Excel con formato fijo:
 * una fila por ingrediente, el corazón se repite en cada fila. Un ingrediente puede
 * ser una materia prima o OTRO corazón (existente o definido en el mismo archivo);
 * no se permiten ciclos.
 *
 * El formato es estricto: si los encabezados no son exactamente los de la
 * plantilla, el archivo se rechaza. Es todo o nada: con un solo error de datos
 * no se importa nada. Un corazón que ya existe se actualiza (su fórmula se
 * reemplaza); uno nuevo se crea. Queda activo solo si la fórmula suma 100%.
 */
class CorazonImportService
{
    public function __construct(
        private readonly CorazonCostService $costos
    ) {}

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
        $codigosArchivo = array_flip(array_filter(array_unique(array_column($filas, 'codigo'))));

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
            } elseif ($f['ingrediente'] === $f['codigo']) {
                $error($n, "El corazón {$f['codigo']} no puede ser ingrediente de sí mismo.");
                $ok = false;
            } elseif (!$ing && !isset($codigosArchivo[$f['ingrediente']])) {
                $error($n, "El ingrediente {$f['ingrediente']} no existe como materia prima ni como corazón.");
                $ok = false;
            } elseif ($ing && !in_array($ing->tipo, ['materia_prima', 'corazon'], true)) {
                $error($n, "El ingrediente {$f['ingrediente']} no es una materia prima ni un corazón.");
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
                if (isset($c['lineas'][$f['ingrediente']])) {
                    $error($n, "El ingrediente {$f['ingrediente']} está repetido en el corazón {$f['codigo']}.");
                } else {
                    // $ing es null cuando el ingrediente es un corazón que se crea en este mismo archivo
                    $c['lineas'][$f['ingrediente']] = ['ing' => $ing, 'porcentaje' => $pct, 'fila' => $n];
                }
            }
            unset($c);
        }

        if (!$errores) {
            foreach ($this->corazonesEnCiclo($corazones) as $codigo) {
                $fila = collect($filas)->firstWhere('codigo', $codigo)['fila'] ?? null;
                $errores[] = ['fila' => $fila, 'mensaje' => "El corazón {$codigo} quedaría dentro de sí mismo (directa o indirectamente): hay un ciclo entre corazones."];
            }
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

    /**
     * Códigos de los corazones del archivo que terminarían conteniéndose a sí mismos.
     *
     * @return array<int, string>
     */
    private function corazonesEnCiclo(array $corazones): array
    {
        $hijos = function (string $codigo) use ($corazones): array {
            if (isset($corazones[$codigo])) {
                return array_keys($corazones[$codigo]['lineas']);
            }
            // Corazón que ya existe y no viene en el archivo: sus ingredientes actuales
            $id = RawMaterial::where('codigo', $codigo)->where('tipo', 'corazon')->value('id');
            if (!$id) {
                return [];
            }

            return CorazonFormulaLine::where('corazon_id', $id)->join('raw_materials as r', 'r.id', '=', 'corazon_formula_lines.raw_material_id')
                ->pluck('r.codigo')->map(fn ($c) => (string) $c)->all();
        };

        $enCiclo = [];
        foreach (array_keys($corazones) as $inicio) {
            $pendientes = array_keys($corazones[$inicio]['lineas']);
            $vistos = [];
            while ($pendientes) {
                $actual = (string) array_pop($pendientes);
                if ($actual === (string) $inicio) {
                    $enCiclo[] = $inicio;
                    break;
                }
                if (isset($vistos[$actual])) {
                    continue;
                }
                $vistos[$actual] = true;
                array_push($pendientes, ...$hijos($actual));
            }
        }

        return $enCiclo;
    }

    /** Primero los corazones que otros del archivo necesitan como ingrediente. */
    private function ordenarPorDependencia(array $corazones): array
    {
        $listos = [];
        $visto = [];
        $visitar = function (string $codigo) use (&$visitar, &$listos, &$visto, $corazones) {
            if (isset($visto[$codigo]) || !isset($corazones[$codigo])) {
                return;
            }
            $visto[$codigo] = true;
            foreach (array_keys($corazones[$codigo]['lineas']) as $hijo) {
                $visitar((string) $hijo);
            }
            $listos[] = $corazones[$codigo]; // se agrega cuando sus dependencias ya están
        };
        foreach (array_keys($corazones) as $codigo) {
            $visitar((string) $codigo);
        }

        return $listos;
    }

    private function aplicar(array $corazones): void
    {
        foreach ($this->ordenarPorDependencia($corazones) as $c) {
            $suma = array_sum(array_column($c['lineas'], 'porcentaje'));
            $datos = ['nombre' => $c['nombre'], 'activo' => abs($suma - 100) < 0.01];
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

            foreach ($c['lineas'] as $codigoIng => $l) {
                $ingId = RawMaterial::where('codigo', (string) $codigoIng)->value('id');
                CorazonFormulaLine::create(['corazon_id' => $corazon->id, 'raw_material_id' => $ingId, 'porcentaje' => $l['porcentaje']]);
            }

            // costo según la fórmula (con los costos ya vigentes de sus ingredientes) y
            // propagación a los productos y corazones que lo usan
            $this->costos->recalcular($corazon);
        }
    }

    private function resumen(array $corazones): array
    {
        $creados = $actualizados = $activos = $conPendientes = 0;
        $detalle = [];
        foreach ($corazones as $c) {
            $suma = round(array_sum(array_column($c['lineas'], 'porcentaje')), 4);
            $activo = abs($suma - 100) < 0.01;
            $pendientes = count(array_filter($c['lineas'], fn ($l) => $l['ing']?->pendiente_equivalencia));
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

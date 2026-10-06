<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reemplaza TODOS los productos terminados por los del CSV de referencias
 * Swissarom (separado por ";", columnas id;codigo;nombre;componente_N;
 * compN_referencia;compNporc… hasta 10 componentes;total).
 *
 * - Cada componente se busca por código en raw_materials (materia prima o corazón).
 * - Si el producto repite un ingrediente, los porcentajes se suman.
 * - Ingredientes que no existen: no se cargan y quedan anotados en
 *   `observaciones`; el producto queda en borrador.
 * - Activo solo si la fórmula suma 100% (±0,01) y no faltó nada.
 * - Códigos de producto repetidos en el CSV: se cargan con sufijo -2, -3…
 *
 * Destructivo y de un solo uso: correr con --dry-run primero.
 */
class ImportarProductosTerminadosCsv extends Command
{
    protected $signature = 'productos-terminados:importar-csv
                            {path : Ruta al CSV}
                            {--dry-run : Solo muestra el resumen, no escribe nada}
                            {--force : Omite la confirmación interactiva}';

    protected $description = 'Borra todos los productos terminados y los reemplaza con los del CSV (destructivo)';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error("No existe el archivo: {$path}");
            return self::FAILURE;
        }

        $productos = $this->leer($path);
        $ingredientes = DB::table('raw_materials')->get(['id', 'codigo', 'costo_unitario', 'unidad'])->keyBy(fn ($r) => trim((string) $r->codigo));

        $plan = $this->planificar($productos, $ingredientes);

        $this->info('Productos en el CSV: ' . count($plan));
        $this->info('Productos terminados actuales que se borran: ' . DB::table('productos_terminados')->count());
        $this->info('Líneas de fórmula a crear: ' . array_sum(array_map(fn ($p) => count($p['lineas']), $plan)));
        $this->info('Quedarán activos: ' . count(array_filter($plan, fn ($p) => $p['activo'])));
        $this->info('Con ingredientes no encontrados: ' . count(array_filter($plan, fn ($p) => $p['faltantes'])));
        $this->info('No suman 100%: ' . count(array_filter($plan, fn ($p) => !$p['faltantes'] && abs($p['suma'] - 100) >= 0.01)));

        if ($this->option('dry-run')) {
            $this->info('(dry-run: no se escribió nada)');
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm('Esto borra TODOS los productos terminados y los reemplaza. ¿Continuar?')) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            DB::table('producto_formula_lines')->delete();
            DB::table('productos_terminados')->delete();

            $ahora = now();
            foreach (array_chunk($plan, 500) as $lote) {
                DB::table('productos_terminados')->insert(array_map(fn ($p) => [
                    'codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'observaciones' => $p['observaciones'],
                    'costo_unitario' => $p['costo'], 'activo' => $p['activo'], 'created_at' => $ahora, 'updated_at' => $ahora,
                ], $lote));
            }

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("UPDATE productos_terminados SET consecutivo = CONCAT('PT-', LPAD(id, 5, '0'))");
            } else {
                foreach (DB::table('productos_terminados')->pluck('id') as $id) {
                    DB::table('productos_terminados')->where('id', $id)->update(['consecutivo' => 'PT-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
                }
            }
            $ids = DB::table('productos_terminados')->pluck('id', 'codigo');

            $lineas = [];
            foreach ($plan as $p) {
                foreach ($p['lineas'] as $rmId => $pct) {
                    $lineas[] = ['producto_terminado_id' => $ids[$p['codigo']], 'raw_material_id' => $rmId,
                                 'porcentaje' => round($pct, 4), 'created_at' => $ahora, 'updated_at' => $ahora];
                }
            }
            foreach (array_chunk($lineas, 1000) as $lote) {
                DB::table('producto_formula_lines')->insert($lote);
            }
        });

        $this->info('Importación terminada: ' . DB::table('productos_terminados')->count() . ' productos, '
            . DB::table('producto_formula_lines')->count() . ' líneas.');

        return self::SUCCESS;
    }

    /** @return array<int, array{codigo: string, nombre: string, componentes: array<int, array{ref: string, pct: float}>}> */
    public function leer(string $path): array
    {
        $contenido = file_get_contents($path);
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $filas = array_map(fn ($l) => str_getcsv($l, ';'), preg_split('/\r\n|\n|\r/', trim($contenido)));
        array_shift($filas);

        $productos = [];
        foreach ($filas as $f) {
            $f = array_pad($f, 34, '');
            $componentes = [];
            for ($k = 0; $k < 10; $k++) {
                $ref = trim((string) $f[4 + $k * 3]);
                $pct = (float) str_replace(',', '.', (string) $f[5 + $k * 3]);
                if ($ref === '' || $ref === '0' || $pct <= 0) {
                    continue;
                }
                $componentes[] = ['ref' => $ref, 'pct' => $pct];
            }
            $productos[] = ['codigo' => trim((string) $f[1]), 'nombre' => mb_substr(trim((string) $f[2]), 0, 255), 'componentes' => $componentes];
        }

        return $productos;
    }

    public function planificar(array $productos, $ingredientes): array
    {
        $usados = [];
        $plan = [];
        foreach ($productos as $p) {
            $codigo = $p['codigo'];
            $usados[$codigo] = ($usados[$codigo] ?? 0) + 1;
            $notas = [];
            if ($usados[$codigo] > 1) {
                $notas[] = "Código repetido en el archivo de origen: se cargó como {$codigo}-{$usados[$codigo]}.";
                $codigo .= '-' . $usados[$codigo];
            }

            $lineas = [];
            $faltantes = [];
            $suma = 0.0;
            $costo = 0.0;
            foreach ($p['componentes'] as $c) {
                $suma += $c['pct'];
                $rm = $ingredientes[$c['ref']] ?? null;
                if (!$rm) {
                    $faltantes[] = "{$c['ref']} ({$c['pct']}%)";
                    continue;
                }
                $lineas[$rm->id] = ($lineas[$rm->id] ?? 0) + $c['pct'];
                $factor = in_array($rm->unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0;
                $costo += ($c['pct'] / 100) * ((float) $rm->costo_unitario / $factor);
            }

            if ($faltantes) {
                $notas[] = 'Ingredientes no encontrados al importar: ' . implode(', ', $faltantes) . '.';
            }
            if (!$p['componentes']) {
                $notas[] = 'El archivo de origen no traía fórmula.';
            } elseif (abs($suma - 100) >= 0.01) {
                $notas[] = 'La fórmula de origen suma ' . round($suma, 4) . '%.';
            }

            $plan[] = [
                'codigo' => $codigo, 'nombre' => $p['nombre'] ?: $codigo, 'lineas' => $lineas, 'suma' => $suma,
                'faltantes' => $faltantes, 'costo' => round($costo, 4),
                'activo' => !$faltantes && $p['componentes'] && abs($suma - 100) < 0.01,
                'observaciones' => $notas ? implode("\n", $notas) : null,
            ];
        }

        return $plan;
    }
}

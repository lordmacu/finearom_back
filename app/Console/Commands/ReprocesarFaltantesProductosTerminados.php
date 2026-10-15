<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reprocesa los productos terminados que quedaron con "Ingredientes no encontrados al
 * importar" (texto guardado en `observaciones`).
 *
 * Ese texto es una foto del momento de la importación y no se reevalúa solo. Este comando
 * vuelve a buscar cada código faltante en TODO lo que hoy existe (materias primas, corazones
 * y, si no hay una materia prima/corazón con ese código, productos terminados), carga las líneas que ahora sí existen, recalcula suma, costo, observaciones y
 * estado, y activa el producto si ya no le falta nada y suma 100% (±0,01).
 *
 * Es idempotente: se puede correr cada vez que se cargue un lote de materias primas o
 * corazones. Los códigos que siguen sin existir se dejan anotados y no se tocan.
 */
class ReprocesarFaltantesProductosTerminados extends Command
{
    protected $signature = 'productos-terminados:reprocesar-faltantes {--dry-run : Solo muestra el resumen, no escribe nada}';

    protected $description = 'Vuelve a buscar los ingredientes faltantes de productos terminados y activa los que queden completos';

    private const PREFIJO = 'Ingredientes no encontrados al importar: ';

    public function handle(): int
    {
        $productos = DB::table('productos_terminados')
            ->where('observaciones', 'like', '%no encontrados al importar%')
            ->orderBy('id')
            ->get(['id', 'codigo', 'observaciones', 'activo']);

        // Faltantes de cada producto: [codigo => pct]; el resto de la nota se conserva
        $parseados = [];
        $codigos = [];
        foreach ($productos as $p) {
            $lineas = explode("\n", (string) $p->observaciones);
            $idx = null;
            foreach ($lineas as $i => $l) {
                if (str_starts_with($l, self::PREFIJO)) {
                    $idx = $i;
                }
            }
            if ($idx === null) {
                continue;
            }
            $items = [];
            foreach (explode(', ', rtrim(substr($lineas[$idx], strlen(self::PREFIJO)), '.')) as $item) {
                if (preg_match('/^(.*) \(([\d.]+)%\)$/', $item, $m)) {
                    $items[] = ['codigo' => (string) $m[1], 'pct' => (float) $m[2], 'texto' => $item];
                    $codigos[(string) $m[1]] = true;
                } else {
                    $items[] = ['codigo' => null, 'pct' => 0.0, 'texto' => $item];
                }
            }
            $parseados[$p->id] = ['lineas' => $lineas, 'idx' => $idx, 'items' => $items, 'codigo' => $p->codigo];
        }

        // Todo lo que existe hoy (strings: nunca comparar como número). Si un código está como
        // materia prima/corazón Y como producto terminado, manda la materia prima/corazón.
        $existentes = [];
        foreach (array_chunk(array_map('strval', array_keys($codigos)), 500) as $lote) {
            foreach (DB::table('productos_terminados')->whereIn('codigo', $lote)->get(['id', 'codigo', 'costo_unitario']) as $pt) {
                $pt->clave = 'pt:' . $pt->id;
                $pt->unidad = 'kg';
                $existentes[(string) $pt->codigo] = $pt;
            }
            foreach (DB::table('raw_materials')->whereIn('codigo', $lote)->get(['id', 'codigo', 'costo_unitario', 'unidad']) as $rm) {
                $rm->clave = 'rm:' . $rm->id;
                $existentes[(string) $rm->codigo] = $rm;
            }
        }

        $r = ['revisados' => count($parseados), 'recuperados' => 0, 'lineas' => 0, 'activados' => 0,
              'siguen_faltantes' => 0, 'no_suman_100' => 0, 'sin_cambio' => 0];
        $siguen = [];
        $ahora = now();

        $aplicar = function () use ($parseados, $existentes, $ahora, &$r, &$siguen) {
            foreach ($parseados as $productoId => $p) {
                $mover = [];
                $quedan = [];
                foreach ($p['items'] as $it) {
                    if ($it['codigo'] !== null && isset($existentes[$it['codigo']])
                        && !($existentes[$it['codigo']]->clave === 'pt:' . $productoId)) { // un producto no va dentro de sí mismo
                        $clave = $existentes[$it['codigo']]->clave;
                        $mover[$clave] = ($mover[$clave] ?? 0) + $it['pct'];
                    } else {
                        $quedan[] = $it;
                    }
                }
                if (!$mover) {
                    $r['sin_cambio']++;
                    foreach ($quedan as $q) {
                        $siguen[$q['codigo'] ?? $q['texto']] = ($siguen[$q['codigo'] ?? $q['texto']] ?? 0) + 1;
                    }
                    continue;
                }

                foreach ($mover as $clave => $pct) {
                    $r['lineas']++;
                    if ($this->dry) {
                        continue;
                    }
                    [$tipo, $id] = explode(':', $clave);
                    $col = $tipo === 'pt' ? 'ingrediente_producto_id' : 'raw_material_id';
                    $ex = DB::table('producto_formula_lines')->where('producto_terminado_id', $productoId)->where($col, $id)->first();
                    if ($ex) {
                        DB::table('producto_formula_lines')->where('id', $ex->id)->update(['porcentaje' => round($ex->porcentaje + $pct, 4), 'updated_at' => $ahora]);
                    } else {
                        DB::table('producto_formula_lines')->insert(['producto_terminado_id' => $productoId, $col => $id,
                            'porcentaje' => round($pct, 4), 'created_at' => $ahora, 'updated_at' => $ahora]);
                    }
                }

                // fórmula actual (materias primas/corazones y productos internos)
                $lineasBd = DB::table('producto_formula_lines as l')
                    ->leftJoin('raw_materials as rm', 'rm.id', '=', 'l.raw_material_id')
                    ->leftJoin('productos_terminados as pt', 'pt.id', '=', 'l.ingrediente_producto_id')
                    ->where('l.producto_terminado_id', $productoId)
                    ->get(['l.porcentaje', 'rm.costo_unitario as rm_costo', 'rm.unidad as rm_unidad', 'pt.costo_unitario as pt_costo', 'l.raw_material_id']);
                $suma = (float) $lineasBd->sum('porcentaje');
                $costo = 0.0;
                foreach ($lineasBd as $l) {
                    $kg = $l->raw_material_id !== null
                        ? (float) $l->rm_costo / (in_array($l->rm_unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0)
                        : (float) $l->pt_costo;
                    $costo += ((float) $l->porcentaje / 100) * $kg;
                }
                if ($this->dry) { // en seco las líneas no se escribieron: se suman a mano
                    $suma += array_sum($mover);
                    foreach ($mover as $clave => $pct) {
                        $x = collect($existentes)->first(fn ($e) => $e->clave === $clave);
                        $kg = (float) $x->costo_unitario / (in_array($x->unidad, ['g', 'ml'], true) ? 1 / 1000 : 1.0);
                        $costo += ($pct / 100) * $kg;
                    }
                }

                $notas = [];
                foreach ($p['lineas'] as $i => $l) {
                    if ($i === $p['idx'] || str_starts_with($l, 'La fórmula de origen suma') || str_starts_with($l, 'La fórmula suma')) {
                        continue;
                    }
                    $notas[] = $l;
                }
                if ($quedan) {
                    $notas[] = self::PREFIJO . implode(', ', array_column($quedan, 'texto')) . '.';
                }
                $activo = !$quedan && abs($suma - 100) < 0.01;
                if (!$quedan && !$activo) {
                    $notas[] = 'La fórmula suma ' . round($suma, 4) . '%.';
                }

                $r['recuperados']++;
                if ($quedan) {
                    $r['siguen_faltantes']++;
                    foreach ($quedan as $q) {
                        $siguen[$q['codigo'] ?? $q['texto']] = ($siguen[$q['codigo'] ?? $q['texto']] ?? 0) + 1;
                    }
                } elseif ($activo) {
                    $r['activados']++;
                } else {
                    $r['no_suman_100']++;
                }

                if (!$this->dry) {
                    DB::table('productos_terminados')->where('id', $productoId)->update([
                        'observaciones'  => $notas ? implode("\n", $notas) : null,
                        'activo'         => $activo,
                        'costo_unitario' => round($costo, 4),
                        'updated_at'     => $ahora,
                    ]);
                    // los productos que contienen a este también cambian de costo
                    $producto = \App\Models\ProductoTerminado::find($productoId);
                    if ($producto) {
                        $padres = DB::table('producto_formula_lines')->where('ingrediente_producto_id', $productoId)->pluck('producto_terminado_id')->unique();
                        foreach ($padres as $padreId) {
                            $padre = \App\Models\ProductoTerminado::find($padreId);
                            if ($padre) {
                                app(\App\Services\ProductoCostService::class)->recalcular($padre);
                            }
                        }
                    }
                }
            }
        };

        $this->dry = (bool) $this->option('dry-run');
        $this->dry ? $aplicar() : DB::transaction($aplicar);

        $this->info(($this->dry ? '[dry-run] ' : '') . "Productos con faltantes revisados: {$r['revisados']}");
        $this->info("Con algún ingrediente recuperado: {$r['recuperados']} (líneas de fórmula cargadas: {$r['lineas']})");
        $this->info("  quedan activos (completos y suman 100%): {$r['activados']}");
        $this->info("  completos pero no suman 100%: {$r['no_suman_100']}");
        $this->info("  recuperaron algo pero aún les falta otro ingrediente: {$r['siguen_faltantes']}");
        $this->info("Sin ningún ingrediente recuperable (sin cambio): {$r['sin_cambio']}");
        arsort($siguen);
        if ($siguen) {
            $this->line('Códigos que siguen sin existir (productos afectados), los 10 mayores:');
            foreach (array_slice($siguen, 0, 10, true) as $codigo => $n) {
                $this->line("  {$codigo}: {$n}");
            }
        }
        if ($this->dry) {
            $this->warn('(dry-run: no se escribió nada)');
        }

        return self::SUCCESS;
    }

    private bool $dry = false;
}

<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use App\Models\ProjectPotentialReference;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de demostración para "Potencial a la vista" sobre proyectos existentes.
 *
 * Toma unos proyectos por ejecutiva real (tabla executives ∩ users), les asigna
 * la ejecutiva y el ingeniero de desarrollo, les crea variantes "[DEMO]" con
 * referencias (código y precio) y marca algunas como seleccionadas por el
 * cliente con su potencial; el del proyecto queda como la suma. Los valores
 * originales quedan en storage/app/demo/potencial-backup.json y --purge los
 * restaura y borra las variantes de demo.
 */
class SeedProjectPotentialDemo extends Command
{
    protected $signature = 'projects:demo-potencial
                            {--per-ejecutiva=5 : Proyectos a preparar por ejecutiva}
                            {--purge : Restaura los proyectos y borra los datos de demo}';

    protected $description = 'Crea (o revierte) datos de demo para el módulo Potencial a la vista';

    private const BACKUP = 'demo/potencial-backup.json';
    private const PREFIX = '[DEMO] ';
    private const BACKUP_FIELDS = ['ejecutivo', 'ejecutivo_id', 'desarrollador_id', 'potencial_anual_usd', 'potencial_anual_kg'];

    private const NOMBRES = [
        'Brisa Marina', 'Vainilla Cálida', 'Lavanda Fresh', 'Coco Tropical', 'Citrus Burst',
        'Jazmín Nocturno', 'Sándalo Suave', 'Frutos Rojos', 'Té Verde', 'Aloe Pure',
        'Rosa Imperial', 'Menta Glacial', 'Almendra Dulce', 'Talco Bebé', 'Maderas Nobles',
    ];

    public function handle(): int
    {
        return $this->option('purge') ? $this->purge() : $this->seed();
    }

    private function seed(): int
    {
        if (Storage::disk('local')->exists(self::BACKUP)) {
            $this->error('Ya hay datos de demo cargados. Corre primero con --purge.');
            return self::FAILURE;
        }

        $ejecutivas = DB::table('executives as e')
            ->join('users as u', DB::raw('LOWER(e.email) COLLATE utf8mb4_general_ci'), '=', DB::raw('LOWER(u.email) COLLATE utf8mb4_general_ci'))
            ->where('e.is_active', true)
            ->orderBy('e.name')
            ->get(['u.id', 'u.name', 'u.email']);

        if ($ejecutivas->isEmpty()) {
            $this->error('No hay ejecutivas activas con usuario.');
            return self::FAILURE;
        }

        $desarrolladorId = User::role('Desarrollo')->where('email', 'not like', '%@example.com')->value('id');
        $porEjecutiva    = max(1, (int) $this->option('per-ejecutiva'));
        mt_srand(20260916);

        $backup = [];
        $usados = [];

        DB::transaction(function () use ($ejecutivas, $desarrolladorId, $porEjecutiva, &$backup, &$usados) {
            foreach ($ejecutivas as $ejecutiva) {
                $proyectos = $this->elegirProyectos($ejecutiva->email, $porEjecutiva, $usados);

                foreach ($proyectos as $i => $project) {
                    $usados[] = $project->id;
                    $backup[] = ['id' => $project->id] + $project->only(self::BACKUP_FIELDS);

                    $project->update([
                        'ejecutivo'        => $ejecutiva->name,
                        'ejecutivo_id'     => $ejecutiva->id,
                        'desarrollador_id' => $project->desarrollador_id ?? $desarrolladorId,
                    ]);

                    $referencias = $this->crearVariantes($project, $i);
                    // El segundo de cada ejecutiva queda sin selección (el cliente aún no elige)
                    $this->seleccionar($project, $i === 1 ? [] : $referencias);
                }

                $this->line("  {$ejecutiva->name}: " . $proyectos->pluck('id')->implode(', '));
            }
        });

        Storage::disk('local')->put(self::BACKUP, json_encode($backup, JSON_PRETTY_PRINT));

        $this->info(count($backup) . ' proyectos preparados. Respaldo: storage/app/' . self::BACKUP);
        return self::SUCCESS;
    }

    /** Prefiere los proyectos que ya eran de la ejecutiva (usuario legacy = parte local del correo). */
    private function elegirProyectos(string $email, int $cantidad, array $excluir)
    {
        $legacy = strtolower(strstr($email, '@', true));

        $base = fn () => Project::query()
            ->whereNotIn('id', $excluir)
            ->whereDoesntHave('marketingVariants')
            ->orderByDesc('id');

        $propios = $base()->where('ejecutivo', $legacy)->limit($cantidad)->get();

        return $propios->concat(
            $base()->whereNotIn('id', $propios->pluck('id'))->where('estado_externo', 'En espera')
                ->limit($cantidad - $propios->count())->get()
        )->values();
    }

    private function crearVariantes(Project $project, int $indice): array
    {
        $creadas = [];

        // El tercer proyecto de cada ejecutiva queda sin referencias (Desarrollo aún no entrega)
        if ($indice === 2) {
            return $creadas;
        }

        foreach (range(1, mt_rand(1, 2)) as $orden) {
            $variant = ProjectMarketingVariant::create([
                'project_id' => $project->id,
                'nombre'     => self::PREFIX . "Variante {$orden}",
                'orden'      => $orden - 1,
            ]);

            foreach (range(0, mt_rand(0, 2)) as $r) {
                $nombre = self::NOMBRES[mt_rand(0, count(self::NOMBRES) - 1)];
                $creadas[] = $variant->references()->create([
                    'referencia' => $nombre,
                    'codigo'     => 'FA-' . mt_rand(10000, 99999),
                    'aplicacion' => $project->tipo_producto,
                    'dosis'      => mt_rand(5, 30) / 10,
                    // Algunas referencias sin precio para ver el caso pendiente
                    'precio'     => mt_rand(1, 5) === 1 ? null : mt_rand(800, 4500) / 100,
                    'orden'      => $r,
                ]);
            }
        }

        return $creadas;
    }

    /** El cliente elige la mitad (al menos una) y el potencial del proyecto es su suma. */
    private function seleccionar(Project $project, array $referencias): void
    {
        $elegidas = array_slice($referencias, 0, (int) ceil(count($referencias) / 2));
        $kgTotal  = 0;
        $usdTotal = 0;

        foreach ($elegidas as $ref) {
            $kg = mt_rand(4, 60) * 50;
            ProjectPotentialReference::create([
                'project_id'            => $project->id,
                'reference_id'          => $ref->id,
                'kg_anio'               => $kg,
                'fecha_primer_despacho' => now()->addMonths(mt_rand(1, 10))->startOfMonth(),
                'venta_anio_usd'        => mt_rand(0, 1) ? round($kg * (float) $ref->precio / mt_rand(2, 6), 2) : null,
                'frecuencia_compra'     => ProjectPotentialReference::FRECUENCIAS[mt_rand(1, 5)],
                'seguimiento'           => ['Panel consumidor', 'Pendiente muestra', 'Estimar volumen', null][mt_rand(0, 3)],
                'estado'                => ProjectPotentialReference::ESTADOS[mt_rand(0, 3)],
                'probabilidad'          => ProjectPotentialReference::PROBABILIDADES[mt_rand(0, 2)],
            ]);
            $kgTotal  += $kg;
            $usdTotal += $kg * (float) $ref->precio;
        }

        $project->update([
            'potencial_anual_kg'  => $elegidas ? $kgTotal : null,
            'potencial_anual_usd' => $elegidas ? round($usdTotal, 2) : null,
        ]);
    }

    private function purge(): int
    {
        if (!Storage::disk('local')->exists(self::BACKUP)) {
            $this->warn('No hay respaldo de datos de demo; no hay nada que revertir.');
            return self::SUCCESS;
        }

        $backup = json_decode(Storage::disk('local')->get(self::BACKUP), true);

        DB::transaction(function () use ($backup) {
            foreach ($backup as $original) {
                $id = $original['id'];
                unset($original['id']);

                Project::withTrashed()->whereKey($id)->update($original);
            }

            // Las referencias se borran en cascada con la variante
            ProjectMarketingVariant::whereIn('project_id', array_column($backup, 'id'))
                ->where('nombre', 'like', self::PREFIX . '%')
                ->get()
                ->each->delete();
        });

        Storage::disk('local')->delete(self::BACKUP);

        $this->info(count($backup) . ' proyectos restaurados y datos de demo eliminados.');
        return self::SUCCESS;
    }
}

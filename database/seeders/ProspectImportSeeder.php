<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProspectImportSeeder extends Seeder
{
    private \PDO $legacyPdo;

    // Counts for final report
    private int $prospectsCreated = 0;
    private int $prospectsSkipped = 0;
    private int $projectsImported = 0;
    private int $projectsSkipped = 0;
    private int $samplesImported = 0;
    private int $evaluationsImported = 0;
    private int $applicationsImported = 0;
    private int $marketingImported = 0;
    private array $errors = [];

    public function run(): void
    {
        $this->command->info('=== ProspectImportSeeder ===');
        $this->command->info('Connecting to legacy database...');

        $this->legacyPdo = new \PDO(
            'mysql:host=proyectosold-db;dbname=syssatau_arom;charset=utf8mb4',
            'syssatau',
            'syssatau_pass',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        $this->command->info('Connected to legacy DB.');

        // Load all NITs → client_id mapping from the new system clients
        $existingNits = DB::table('clients')
            ->whereNotNull('nit')
            ->where('nit', '!=', '')
            ->get(['id', 'nit'])
            ->mapWithKeys(fn($c) => [$this->normalizeNit($c->nit) => $c->id])
            ->filter(fn($id, $nit) => !empty($nit))
            ->toArray();

        $this->command->info('Loaded ' . count($existingNits) . ' NITs from existing clients.');

        // Get all legacy projects that are NOT already imported
        $importedIds = DB::table('projects')
            ->whereNotNull('legacy_id')
            ->pluck('legacy_id')
            ->toArray();

        // Also consider projects imported by ID (before this seeder added legacy_id column)
        // The existing imported projects have IDs matching legacy IDs
        $existingProjectIds = DB::table('projects')
            ->pluck('id')
            ->toArray();

        $this->command->info('Already have ' . count($existingProjectIds) . ' projects in new system.');

        // Get all legacy clients that have projects, grouped
        $stmt = $this->legacyPdo->query(
            'SELECT DISTINCT c.id, c.nombre, c.nit, c.clasificacion, c.ejecutivo
             FROM clientes c
             INNER JOIN proyectos p ON p.cliente_id = c.id
             ORDER BY c.id'
        );
        $legacyClients = $stmt->fetchAll();

        $this->command->info('Found ' . count($legacyClients) . ' legacy clients with projects.');

        $bar = $this->command->getOutput()->createProgressBar(count($legacyClients));
        $bar->start();

        foreach ($legacyClients as $legacyClient) {
            $bar->advance();

            $normalizedNit = $this->normalizeNit($legacyClient['nit']);

            // If NIT matches an existing client, import projects with client_id
            if ($normalizedNit && isset($existingNits[$normalizedNit])) {
                $this->prospectsSkipped++;
                $clientId = $existingNits[$normalizedNit];
                $this->importProjectsForEntity($legacyClient['id'], $clientId, null, $importedIds);
                continue;
            }

            // Create or retrieve the prospect
            $prospect = DB::table('prospects')
                ->where('legacy_id', $legacyClient['id'])
                ->first();

            if (!$prospect) {
                // Assign placeholder NIT if missing/invalid
                $nit = ($normalizedNit && $normalizedNit !== '0')
                    ? $legacyClient['nit']
                    : 'PROSPECT-' . $legacyClient['id'];

                $prospectId = DB::table('prospects')->insertGetId([
                    'nombre'      => $legacyClient['nombre'],
                    'nit'         => $nit,
                    'tipo_cliente' => $this->mapClasificacion($legacyClient['clasificacion']),
                    'ejecutivo'   => $legacyClient['ejecutivo'] ?: null,
                    'legacy_id'   => $legacyClient['id'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $this->prospectsCreated++;
            } else {
                $prospectId = $prospect->id;
            }

            // Import projects for this prospect
            $this->importProjectsForProspect(
                (int) $legacyClient['id'],
                (int) $prospectId,
                $importedIds
            );
        }

        // Import orphaned projects (cliente_id not in clientes table)
        $orphanedStmt = $this->legacyPdo->query(
            'SELECT DISTINCT p.cliente_id FROM proyectos p
             WHERE p.cliente_id NOT IN (SELECT id FROM clientes)'
        );
        $orphanedClientIds = $orphanedStmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!empty($orphanedClientIds)) {
            $this->command->info('Processing ' . count($orphanedClientIds) . ' orphaned cliente_ids...');
            foreach ($orphanedClientIds as $legacyClientId) {
                $legacyClientId = (int) $legacyClientId;
                $prospect = DB::table('prospects')->where('legacy_id', $legacyClientId)->first();
                if (!$prospect) {
                    $prospectId = DB::table('prospects')->insertGetId([
                        'nombre'      => 'Cliente desconocido #' . $legacyClientId,
                        'nit'         => 'PROSPECT-' . $legacyClientId,
                        'tipo_cliente' => 'none',
                        'ejecutivo'   => null,
                        'legacy_id'   => $legacyClientId,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                    $this->prospectsCreated++;
                } else {
                    $prospectId = $prospect->id;
                }
                $this->importProjectsForEntity($legacyClientId, null, $prospectId, $importedIds);
            }
        }

        $bar->finish();
        $this->command->newLine(2);

        $this->command->info('=== Import Complete ===');
        $this->command->info("Prospects created:    {$this->prospectsCreated}");
        $this->command->info("Prospects skipped (existing clients): {$this->prospectsSkipped}");
        $this->command->info("Projects imported:    {$this->projectsImported}");
        $this->command->info("Projects skipped (already exist): {$this->projectsSkipped}");
        $this->command->info("  Samples imported:      {$this->samplesImported}");
        $this->command->info("  Evaluations imported:  {$this->evaluationsImported}");
        $this->command->info("  Applications imported: {$this->applicationsImported}");
        $this->command->info("  Marketing imported:    {$this->marketingImported}");

        if (!empty($this->errors)) {
            $this->command->warn('Errors encountered (' . count($this->errors) . '):');
            foreach (array_slice($this->errors, 0, 20) as $err) {
                $this->command->warn('  ' . $err);
            }
            if (count($this->errors) > 20) {
                $this->command->warn('  ... and ' . (count($this->errors) - 20) . ' more errors.');
            }
        } else {
            $this->command->info('No errors encountered.');
        }
    }

    private function importProjectsForProspect(int $legacyClientId, int $prospectId, array $existingProjectIds): void
    {
        $this->importProjectsForEntity($legacyClientId, null, $prospectId, $existingProjectIds);
    }

    private function importProjectsForEntity(int $legacyClientId, ?int $clientId, ?int $prospectId, array $importedIds): void
    {
        $stmt = $this->legacyPdo->prepare(
            'SELECT * FROM proyectos WHERE cliente_id = :cliente_id'
        );
        $stmt->execute(['cliente_id' => $legacyClientId]);
        $legacyProjects = $stmt->fetchAll();

        foreach ($legacyProjects as $lp) {
            try {
                // Check if project already exists by legacy_id column
                $existingByLegacyId = DB::table('projects')
                    ->where('legacy_id', $lp['id'])
                    ->value('id');

                if ($existingByLegacyId) {
                    $this->importSampleIfMissing($lp['id'], (int) $existingByLegacyId);
                    $this->importEvaluationIfMissing($lp['id'], (int) $existingByLegacyId);
                    $this->importApplicationIfMissing($lp['id'], (int) $existingByLegacyId);
                    $this->importMarketingIfMissing($lp['id'], (int) $existingByLegacyId);
                    $this->projectsSkipped++;
                    continue;
                }

                if (in_array($lp['id'], $importedIds)) {
                    $this->projectsSkipped++;
                    continue;
                }

                $newProjectId = $this->insertProject($lp, $clientId, $prospectId);
                $this->projectsImported++;
                $importedIds[] = $lp['id'];

                $this->importSample($lp['id'], $newProjectId);
                $this->importEvaluation($lp['id'], $newProjectId);
                $this->importApplication($lp['id'], $newProjectId);
                $this->importMarketing($lp['id'], $newProjectId);
            } catch (\Throwable $e) {
                $this->errors[] = "Project legacy_id={$lp['id']}: " . $e->getMessage();
            }
        }
    }

    private function importSampleIfMissing(int $legacyProjectId, int $newProjectId): void
    {
        $exists = DB::table('project_samples')->where('project_id', $newProjectId)->exists();
        if (!$exists) {
            $this->importSample($legacyProjectId, $newProjectId);
        }
    }

    private function importEvaluationIfMissing(int $legacyProjectId, int $newProjectId): void
    {
        $exists = DB::table('project_evaluations')->where('project_id', $newProjectId)->exists();
        if (!$exists) {
            $this->importEvaluation($legacyProjectId, $newProjectId);
        }
    }

    private function importApplicationIfMissing(int $legacyProjectId, int $newProjectId): void
    {
        $exists = DB::table('project_applications')->where('project_id', $newProjectId)->exists();
        if (!$exists) {
            $this->importApplication($legacyProjectId, $newProjectId);
        }
    }

    private function importMarketingIfMissing(int $legacyProjectId, int $newProjectId): void
    {
        $exists = DB::table('project_marketing')->where('project_id', $newProjectId)->exists();
        if (!$exists) {
            $this->importMarketing($legacyProjectId, $newProjectId);
        }
    }

    private function insertProject(array $lp, ?int $clientId, ?int $prospectId): int
    {
        $tipo = $this->mapTipo($lp['tipo']);
        $estadoExterno = $this->mapEstadoExterno($lp['estado_externo']);
        $estadoInterno = $this->mapEstadoInterno($lp['estado_interno']);

        return DB::table('projects')->insertGetId([
            'nombre'               => $lp['nombre'],
            'client_id'            => $clientId,
            'prospect_id'          => $prospectId,
            'legacy_id'            => $lp['id'],
            'nombre_prospecto'     => null,
            'email_prospecto'      => null,
            'product_id'           => $this->productIdOrNull($lp['producto_id']),
            'tipo'                 => $tipo,
            'rango_min'            => $this->nullableDecimal($lp['rango_min']),
            'rango_max'            => $this->nullableDecimal($lp['rango_max']),
            'volumen'              => $this->nullableDecimal($lp['volumen']),
            'base_cliente'         => $this->mapBool($lp['base_cliente']),
            'proactivo'            => $this->mapBool($lp['proactivo']),
            'fecha_requerida'      => $this->nullableDate($lp['fecha_requerida']),
            'fecha_creacion'       => $lp['fecha_creacion'],
            'fecha_calculada'      => $this->nullableDate($lp['fecha_calculada']),
            'fecha_entrega'        => $this->nullableDate($lp['fecha_entrega']),
            'dias_diferencia'      => null,
            'tipo_producto'        => $lp['tipo_producto'] ?: null,
            'trm'                  => $this->nullableDecimal($lp['trm']),
            'factor'               => $lp['factor'] ?? 1.0,
            'homologacion'         => $this->mapBool($lp['homologacion']),
            'internacional'        => $this->mapBool($lp['internacional']),
            'ejecutivo'            => $lp['ejecutivo'] ?: null,
            'ejecutivo_id'         => null,
            'estado_externo'       => $estadoExterno,
            'razon_perdida'        => null,
            'fecha_externo'        => $this->nullableDate($lp['fecha_externo']),
            'ejecutivo_externo'    => $lp['ejecutivo_externo'] ?: null,
            'estado_interno'       => $estadoInterno,
            'ejecutivo_interno'    => $lp['ejecutivo_interno'] ?: null,
            'estado_desarrollo'    => (int) $lp['estado_desarrollo'],
            'fecha_desarrollo'     => $this->nullableDate($lp['fecha_desarrollo']),
            'ejecutivo_desarrollo' => $lp['ejecutivo_desarrollo'] ?: null,
            'estado_laboratorio'   => (int) $lp['estado_laboratorio'],
            'fecha_laboratorio'    => $this->nullableDate($lp['fecha_laboratorio']),
            'ejecutivo_laboratorio' => $lp['ejecutivo_laboratorio'] ?: null,
            'estado_mercadeo'      => (int) $lp['estado_mercadeo'],
            'fecha_mercadeo'       => $this->nullableDate($lp['fecha_mercadeo']),
            'ejecutivo_mercadeo'   => $lp['ejecutivo_mercadeo'] ?: null,
            'estado_calidad'       => (int) $lp['estado_calidad'],
            'fecha_calidad'        => $this->nullableDate($lp['fecha_calidad']),
            'ejecutivo_calidad'    => $lp['ejecutivo_calidad'] ?: null,
            'estado_especiales'    => (int) $lp['estado_especiales'],
            'fecha_especiales'     => $this->nullableDate($lp['fecha_especiales']),
            'ejecutivo_especiales' => $lp['ejecutivo_especiales'] ?: null,
            'obs_lab'              => $lp['obs_lab'] ?: null,
            'obs_des'              => $lp['obs_des'] ?: null,
            'obs_mer'              => $lp['obs_mer'] ?: null,
            'obs_cal'              => $lp['obs_cal'] ?: null,
            'obs_esp'              => $lp['obs_esp'] ?: null,
            'obs_ext'              => $lp['obs_ext'] ?: null,
            'actualizado'          => (int) $lp['actualizado'],
            'created_at'           => now(),
            'updated_at'           => now(),
            'deleted_at'           => null,
        ]);
    }

    private function importSample(int $legacyProjectId, int $newProjectId): void
    {
        $stmt = $this->legacyPdo->prepare('SELECT * FROM muestra WHERE proyecto_id = :pid');
        $stmt->execute(['pid' => $legacyProjectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            DB::table('project_samples')->insert([
                'project_id'   => $newProjectId,
                'cantidad'     => $row['cantidad'] ?? null,
                'observaciones' => $row['observaciones'] ?: null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $this->samplesImported++;
        }
    }

    private function importEvaluation(int $legacyProjectId, int $newProjectId): void
    {
        $stmt = $this->legacyPdo->prepare('SELECT * FROM evaluacion WHERE proyecto_id = :pid');
        $stmt->execute(['pid' => $legacyProjectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            DB::table('project_evaluations')->insert([
                'project_id'  => $newProjectId,
                'tipos'       => $this->csvToJson($row['tipos']),
                'observacion' => $row['observacion'] ?: null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $this->evaluationsImported++;
        }
    }

    private function importApplication(int $legacyProjectId, int $newProjectId): void
    {
        $stmt = $this->legacyPdo->prepare('SELECT * FROM aplicacion WHERE proyecto_id = :pid');
        $stmt->execute(['pid' => $legacyProjectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            DB::table('project_applications')->insert([
                'project_id'   => $newProjectId,
                'dosis'        => $row['dosis'] ?? null,
                'observaciones' => $row['observaciones'] ?: null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $this->applicationsImported++;
        }
    }

    private function importMarketing(int $legacyProjectId, int $newProjectId): void
    {
        $stmt = $this->legacyPdo->prepare('SELECT * FROM marketing_y_calidad WHERE proyecto_id = :pid');
        $stmt->execute(['pid' => $legacyProjectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            DB::table('project_marketing')->insert([
                'project_id'   => $newProjectId,
                'marketing'    => $this->csvToJson($row['marketing']),
                'calidad'      => $this->csvToJson($row['calidad']),
                'obs_marketing' => $row['obs_marketing'] ?: null,
                'obs_calidad'  => $row['obs_calidad'] ?: null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $this->marketingImported++;
        }
    }

    // ---- Helpers ----

    /**
     * Convert a legacy comma-separated string into a JSON array string.
     * Returns null if empty. Already-valid JSON is passed through as-is.
     */
    private function csvToJson(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $trimmed = trim($value);
        // If it's already valid JSON, pass through
        if (json_validate($trimmed)) {
            return $trimmed;
        }
        // Split by comma and trim each item
        $items = array_map('trim', explode(',', $trimmed));
        $items = array_filter($items, fn($item) => $item !== '');
        if (empty($items)) {
            return null;
        }
        return json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
    }

    private function normalizeNit(?string $nit): string
    {
        if ($nit === null || $nit === '' || $nit === '0') {
            return '';
        }
        // Strip everything except digits
        return preg_replace('/[^0-9]/', '', $nit);
    }

    private function mapClasificacion(?string $clasificacion): string
    {
        return match (strtoupper(trim($clasificacion ?? ''))) {
            'A', 'B' => 'pareto',
            'C'      => 'balance',
            default  => 'none',
        };
    }

    private function mapTipo(string $tipo): string
    {
        // Handle encoding issues (Colecci?n → Colección)
        $normalized = trim($tipo);
        if (str_contains($normalized, 'lecci') || str_contains($normalized, 'lacci')) {
            return 'Colección';
        }
        if (str_contains($normalized, 'Fine') || str_contains($normalized, 'fine')) {
            return 'Fine Fragances';
        }
        if (str_contains($normalized, 'sarrollo') || $normalized === 'Desarrollo') {
            return 'Desarrollo';
        }
        // Direct match fallback
        return match ($normalized) {
            'Colección', 'Colecci?n' => 'Colección',
            'Fine Fragances'         => 'Fine Fragances',
            'Desarrollo'             => 'Desarrollo',
            default                  => 'Desarrollo', // safe fallback
        };
    }

    private function mapEstadoExterno(string $estado): string
    {
        return match (trim($estado)) {
            'Ganado'  => 'Ganado',
            'Perdido' => 'Perdido',
            default   => 'En espera',
        };
    }

    private function mapEstadoInterno(string $estado): string
    {
        return match (trim($estado)) {
            'Entregado' => 'Entregado',
            default     => 'En proceso',
        };
    }

    private function mapBool(?string $value): int
    {
        return (strtolower(trim($value ?? 'no')) === 'si') ? 1 : 0;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        $num = (float) $value;
        // decimal(10,2) max is 99999999.99 — cap overflow values to null
        if ($num > 99999999.99 || $num < -99999999.99) {
            return null;
        }
        return (string) $num;
    }

    private function nullableDate(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    private function productIdOrNull(mixed $productoId): ?int
    {
        if ($productoId === null || $productoId === '' || (int) $productoId === 0) {
            return null;
        }
        // Check if product exists in new system
        static $validProducts = null;
        if ($validProducts === null) {
            $validProducts = DB::table('project_product_types')
                ->pluck('id')
                ->flip()
                ->toArray();
        }
        $id = (int) $productoId;
        return isset($validProducts[$id]) ? $id : null;
    }
}

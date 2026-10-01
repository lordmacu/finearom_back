<?php

namespace App\Console\Commands;

use App\Models\ProductoTerminado;
use App\Models\RawMaterial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reemplaza TODAS las materias primas (tipo=materia_prima) por las filas de un
 * Excel con columnas PROVEEDOR / NOMBRE PROVEEDOR / CÓDIGO PROVEEDOR / CAS /
 * CÓDIGO SWISSAROM / NOMBRE SWISSAROM.
 *
 * Es destructivo a propósito: por las FK en cascada, borra también las líneas
 * de fórmula de corazones, referencias (Top Calificadas) y productos
 * terminados que usaban las materias primas eliminadas. Los corazones y
 * productos terminados afectados quedan reseteados a borrador (activo=false,
 * costo_unitario=0) en vez de quedar "activos" con una composición rota.
 *
 * Un solo uso puntual (no es una pantalla ni un import repetible) — correr
 * con --dry-run primero para ver el resumen sin tocar nada.
 */
class ReemplazarMateriasPrimasDesdeExcel extends Command
{
    protected $signature = 'materias-primas:reemplazar-desde-excel
                            {path : Ruta al archivo .xlsx}
                            {--dry-run : Solo muestra el resumen, no escribe nada}
                            {--force : Omite la confirmación interactiva}';

    protected $description = 'Borra todas las materias primas y las reemplaza con las filas de un Excel (destructivo)';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!is_file($path)) {
            $this->error("No existe el archivo: {$path}");
            return self::FAILURE;
        }

        $rows = $this->readRowsFromExcel($path);

        if (empty($rows)) {
            $this->error('El Excel no tiene ninguna fila válida (código y nombre son obligatorios).');
            return self::FAILURE;
        }

        $actuales = RawMaterial::where('tipo', 'materia_prima')->count();

        $this->info("Filas válidas en el Excel: " . count($rows));
        $this->info("Materias primas actuales que se van a borrar: {$actuales}");

        if ($this->option('dry-run')) {
            $this->info('(dry-run: no se escribió nada)');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Esto borra TODAS las materias primas actuales (cascada incluida) y las reemplaza. ¿Continuar?')) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        $resumen = $this->applyImport($rows);

        $this->info("Materias primas borradas: {$resumen['borradas']}");
        $this->info("Materias primas insertadas: {$resumen['insertadas']}");
        $this->info("Corazones reseteados a borrador: {$resumen['corazones_reseteados']}");
        $this->info("Productos terminados reseteados a borrador: {$resumen['productos_reseteados']}");

        return self::SUCCESS;
    }

    /**
     * Lee el Excel y devuelve solo las filas con código y nombre (las demás
     * se descartan silenciosamente, igual que celdas vacías intermedias).
     *
     * @return array<int, array{codigo: string, nombre: string, cas: ?string, proveedor: ?string, descriptores: ?string}>
     */
    public function readRowsFromExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        $isFirstRow = true;

        foreach ($sheet->getRowIterator() as $row) {
            if ($isFirstRow) {
                $isFirstRow = false;
                continue;
            }

            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $cell->getValue();
            }

            // PROVEEDOR, NOMBRE PROVEEDOR, CÓDIGO PROVEEDOR, CAS, CÓDIGO SWISSAROM, NOMBRE SWISSAROM
            [$proveedor, $nombreProveedor, $codigoProveedor, $cas, $codigoSwissarom, $nombreSwissarom] = array_pad($cells, 6, null);

            $codigo = $this->normalizeString($codigoSwissarom);
            $nombre = $this->normalizeString($nombreSwissarom);

            if ($codigo === null || $nombre === null) {
                continue;
            }

            $descriptoresPartes = [];
            $nombreProveedor = $this->normalizeString($nombreProveedor);
            $codigoProveedor = $this->normalizeString($codigoProveedor);
            if ($nombreProveedor !== null) {
                $descriptoresPartes[] = "Nombre proveedor: {$nombreProveedor}";
            }
            if ($codigoProveedor !== null) {
                $descriptoresPartes[] = "Código proveedor: {$codigoProveedor}";
            }

            $rows[] = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'cas' => $this->normalizeString($cas),
                'proveedor' => $this->normalizeString($proveedor),
                'descriptores' => $descriptoresPartes ? implode(' | ', $descriptoresPartes) : null,
            ];
        }

        return $rows;
    }

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Borra las materias primas actuales (cascada incluida), resetea
     * corazones/productos terminados afectados, e inserta las filas nuevas.
     * Todo dentro de una transacción.
     *
     * @param array<int, array{codigo: string, nombre: string, cas: ?string, proveedor: ?string, descriptores: ?string}> $rows
     * @return array{borradas: int, insertadas: int, corazones_reseteados: int, productos_reseteados: int}
     */
    public function applyImport(array $rows): array
    {
        return DB::transaction(function () use ($rows) {
            $borradas = RawMaterial::where('tipo', 'materia_prima')->count();
            RawMaterial::where('tipo', 'materia_prima')->delete();

            $corazonesReseteados = RawMaterial::where('tipo', 'corazon')->update([
                'activo' => false,
                'costo_unitario' => 0,
            ]);

            $productosReseteados = ProductoTerminado::query()->update([
                'activo' => false,
                'costo_unitario' => 0,
            ]);

            $now = now();
            $registros = array_map(fn (array $row) => [
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'cas' => $row['cas'],
                'descriptores' => $row['descriptores'],
                'tipo' => 'materia_prima',
                'unidad' => 'kg',
                'costo_unitario' => 0,
                'stock_disponible' => 0,
                'proveedor' => $row['proveedor'],
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows);

            foreach (array_chunk($registros, 500) as $chunk) {
                RawMaterial::insert($chunk);
            }

            return [
                'borradas' => $borradas,
                'insertadas' => count($rows),
                'corazones_reseteados' => $corazonesReseteados,
                'productos_reseteados' => $productosReseteados,
            ];
        });
    }
}

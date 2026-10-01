<?php

namespace Tests\Feature;

use App\Console\Commands\ReemplazarMateriasPrimasDesdeExcel;
use App\Models\CorazonFormulaLine;
use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Models\RawMaterial;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReemplazarMateriasPrimasDesdeExcelTest extends ProductoTerminadoTestCase
{
    private function escribirExcelTemporal(array $filas): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(
            ['PROVEEDOR', 'NOMBRE PROVEEDOR', 'CÓDIGO PROVEEDOR', 'CAS', 'CÓDIGO SWISSAROM', 'NOMBRE SWISSAROM'],
            null,
            'A1'
        );
        $sheet->fromArray($filas, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'test_mp_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_lee_filas_validas_y_arma_descriptores_con_datos_del_proveedor(): void
    {
        $path = $this->escribirExcelTemporal([
            ['ACME', 'Producto ACME', 'AC-1', '12-34-5', '100001', 'Bergamota'],
            ['LLUCH', null, null, '', '100002', 'Lemongrass'],
            [null, null, null, null, null, null], // fila totalmente vacía, se descarta
            ['SIN CODIGO', 'Algo', null, '', null, 'Nombre sin código'], // sin código, se descarta
        ]);

        $command = new ReemplazarMateriasPrimasDesdeExcel();
        $rows = $command->readRowsFromExcel($path);
        unlink($path);

        $this->assertCount(2, $rows);

        $this->assertEquals('100001', $rows[0]['codigo']);
        $this->assertEquals('Bergamota', $rows[0]['nombre']);
        $this->assertEquals('12-34-5', $rows[0]['cas']);
        $this->assertEquals('ACME', $rows[0]['proveedor']);
        $this->assertEquals('Nombre proveedor: Producto ACME | Código proveedor: AC-1', $rows[0]['descriptores']);

        $this->assertEquals('100002', $rows[1]['codigo']);
        $this->assertEquals('Lemongrass', $rows[1]['nombre']);
        $this->assertNull($rows[1]['cas']);
        $this->assertEquals('LLUCH', $rows[1]['proveedor']);
        $this->assertNull($rows[1]['descriptores']);
    }

    public function test_apply_import_borra_inserta_y_resetea_corazones_y_productos_afectados(): void
    {
        $mpVieja = $this->materiaPrima(['codigo' => 'VIEJA-1']);
        $corazon = $this->corazon(['activo' => true, 'costo_unitario' => 42]);
        CorazonFormulaLine::create([
            'corazon_id' => $corazon->id,
            'raw_material_id' => $mpVieja->id,
            'porcentaje' => 100,
        ]);
        $producto = ProductoTerminado::create([
            'codigo' => 'PROD-X', 'nombre' => 'X', 'activo' => true, 'costo_unitario' => 7,
        ]);
        ProductoFormulaLine::create([
            'producto_terminado_id' => $producto->id,
            'raw_material_id' => $mpVieja->id,
            'porcentaje' => 100,
        ]);

        $command = new ReemplazarMateriasPrimasDesdeExcel();
        $resumen = $command->applyImport([
            ['codigo' => '100001', 'nombre' => 'Bergamota', 'cas' => null, 'proveedor' => null, 'descriptores' => null],
            ['codigo' => '100002', 'nombre' => 'Lemongrass', 'cas' => null, 'proveedor' => null, 'descriptores' => null],
        ]);

        $this->assertEquals(1, $resumen['borradas']);
        $this->assertEquals(2, $resumen['insertadas']);
        $this->assertEquals(1, $resumen['corazones_reseteados']);
        $this->assertEquals(1, $resumen['productos_reseteados']);

        $this->assertDatabaseMissing('raw_materials', ['id' => $mpVieja->id]);
        $this->assertDatabaseHas('raw_materials', ['codigo' => '100001', 'nombre' => 'Bergamota', 'tipo' => 'materia_prima']);
        $this->assertDatabaseHas('raw_materials', ['codigo' => '100002', 'nombre' => 'Lemongrass', 'tipo' => 'materia_prima']);

        // Nota: el borrado en cascada de corazon_formula_lines/producto_formula_lines
        // lo garantiza la FK real (cascadeOnDelete) de las migraciones de producción;
        // el esquema de prueba no define FKs reales (mismo criterio que el resto de
        // la suite), así que aquí solo se verifica el reseteo que sí hace el comando.
        $this->assertEquals(false, (bool) $corazon->fresh()->activo);
        $this->assertEquals(0.0, (float) $corazon->fresh()->costo_unitario);
        $this->assertEquals(false, (bool) $producto->fresh()->activo);
        $this->assertEquals(0.0, (float) $producto->fresh()->costo_unitario);
    }

    public function test_apply_import_no_escribe_nada_si_algo_falla_a_mitad_de_camino(): void
    {
        $mpVieja = $this->materiaPrima(['codigo' => 'VIEJA-2']);

        // 'nombre' es NOT NULL en el esquema real: la segunda fila fuerza un
        // error de BD a mitad del insert, para probar que la transacción
        // revierte el borrado de la primera fila también.
        try {
            $command = new ReemplazarMateriasPrimasDesdeExcel();
            $command->applyImport([
                ['codigo' => '100003', 'nombre' => 'Fila válida', 'cas' => null, 'proveedor' => null, 'descriptores' => null],
                ['codigo' => '100004', 'nombre' => null, 'cas' => null, 'proveedor' => null, 'descriptores' => null],
            ]);
            $this->fail('Se esperaba que applyImport lanzara una excepción.');
        } catch (\Throwable $e) {
            // esperado: la transacción debe revertirse
        }

        $this->assertDatabaseHas('raw_materials', ['id' => $mpVieja->id]);
        $this->assertDatabaseMissing('raw_materials', ['codigo' => '100003']);
    }
}

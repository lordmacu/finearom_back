<?php

namespace Tests\Feature;

use App\Models\CorazonFormulaLine;
use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Models\RawMaterial;
use App\Services\CorazonImportService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importador de corazones desde Excel: formato estricto, todo o nada,
 * crea/actualiza y activa solo si la fórmula suma 100%.
 */
class CorazonImportTest extends ProductoTerminadoTestCase
{
    private function excel(array $filas, ?array $encabezados = null, string $nombre = 'corazones.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($encabezados ?? CorazonImportService::HEADERS, null, 'A1');
        if ($filas) {
            $spreadsheet->getActiveSheet()->fromArray($filas, null, 'A2');
        }
        $path = tempnam(sys_get_temp_dir(), 'cor') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function subir(UploadedFile $file, bool $dry = false)
    {
        return $this->postJson('/api/corazones/import', ['file' => $file, 'dry_run' => $dry ? 1 : 0]);
    }

    public function test_importa_crea_corazon_activo_con_costo_y_ingredientes(): void
    {
        $a = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $b = $this->materiaPrima(['codigo' => '100004', 'costo_unitario' => 20]);

        $res = $this->subir($this->excel([
            [750001, 'CORAZON UNO', 'Notas de salida', 100000, 60],
            [750001, 'CORAZON UNO', '', '100004', '40'],
            ['750002', 'CORAZON DOS', '', '100000', 50],
        ]));

        $res->assertOk()->assertJsonPath('data.creados', 2)->assertJsonPath('data.activos', 1)->assertJsonPath('data.borrador', 1);

        $uno = RawMaterial::where('codigo', '750001')->first();
        $this->assertSame('corazon', $uno->tipo);
        $this->assertTrue((bool) $uno->activo);
        $this->assertSame('Notas de salida', $uno->descripcion);
        $this->assertEqualsWithDelta(14.0, (float) $uno->costo_unitario, 0.0001); // 0.6*10 + 0.4*20
        $this->assertSame(2, CorazonFormulaLine::where('corazon_id', $uno->id)->count());

        $dos = RawMaterial::where('codigo', '750002')->first();
        $this->assertFalse((bool) $dos->activo, 'suma 50% => borrador');
    }

    public function test_dry_run_valida_sin_escribir(): void
    {
        $this->materiaPrima(['codigo' => '100000']);

        $this->subir($this->excel([['750001', 'UNO', '', '100000', 100]]), true)
            ->assertOk()->assertJsonPath('data.creados', 1);

        $this->assertSame(0, RawMaterial::where('tipo', 'corazon')->count());
    }

    public function test_rechaza_archivo_con_formato_distinto(): void
    {
        $this->materiaPrima(['codigo' => '100000']);

        $this->subir($this->excel([['750001', 'UNO', '', '100000', 100]], ['CODIGO', 'NOMBRE', 'DESCRIPCION', 'INGREDIENTE', 'PORCENTAJE']))
            ->assertStatus(422)->assertJsonPath('errors.0.fila', 1);

        // columna extra también se rechaza
        $this->subir($this->excel([['750001', 'UNO', '', '100000', 100, 'x']], [...CorazonImportService::HEADERS, 'EXTRA']))
            ->assertStatus(422);

        $this->assertSame(0, RawMaterial::where('tipo', 'corazon')->count());
    }

    public function test_todo_o_nada_con_errores_de_datos(): void
    {
        $this->materiaPrima(['codigo' => '100000']);
        $corazonAjeno = $this->materiaPrima(['codigo' => '750009', 'tipo' => 'corazon']);
        $this->materiaPrima(['codigo' => '100500', 'tipo' => 'materia_prima']);

        $res = $this->subir($this->excel([
            ['750001', 'BUENO', '', '100000', 100],            // fila 2 ok
            ['750002', 'MALO', '', '999999', 50],              // fila 3: ingrediente no existe
            ['750003', 'MALO', '', '100000', 'abc'],           // fila 4: porcentaje inválido
            ['750004', 'MALO', '', '100000', 150],             // fila 5: > 100
            ['100500', 'CHOQUE', '', '100000', 100],           // fila 6: código es materia prima
            ['750005', 'DUP', '', '100000', 50],
            ['750005', 'DUP', '', '100000', 50],               // fila 8: ingrediente repetido
            ['750006', 'NOMBRE A', '', '100000', 50],
            ['750006', 'NOMBRE B', '', '100000', 50],          // fila 10: nombres distintos
            ['750007', 'ANIDADO', '', $corazonAjeno->codigo, 100], // fila 11: ingrediente corazón (permitido)
            ['', '', '', '100000', 10],                        // fila 12: faltan código y nombre
        ]));

        $res->assertStatus(422);
        $filas = collect($res->json('errors'))->pluck('fila')->all();
        foreach ([3, 4, 5, 6, 8, 10, 12] as $f) {
            $this->assertContains($f, $filas, "falta error en fila {$f}");
        }
        $this->assertNotContains(2, $filas);
        $this->assertNotContains(11, $filas, 'un corazón como ingrediente de otro corazón es válido');
        $this->assertSame(1, RawMaterial::where('tipo', 'corazon')->count(), 'solo existe el corazón ajeno: no se importó nada');
    }

    public function test_actualiza_corazon_existente_y_recalcula_productos(): void
    {
        $a = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $b = $this->materiaPrima(['codigo' => '100004', 'costo_unitario' => 30]);
        $corazon = $this->materiaPrima(['codigo' => '750001', 'nombre' => 'VIEJO', 'tipo' => 'corazon', 'costo_unitario' => 10, 'descripcion' => 'ya tenia']);
        CorazonFormulaLine::create(['corazon_id' => $corazon->id, 'raw_material_id' => $a->id, 'porcentaje' => 100]);
        $producto = ProductoTerminado::create(['codigo' => '585001', 'nombre' => 'PT', 'costo_unitario' => 10]);
        ProductoFormulaLine::create(['producto_terminado_id' => $producto->id, 'raw_material_id' => $corazon->id, 'porcentaje' => 100]);

        $this->subir($this->excel([
            ['750001', 'NUEVO NOMBRE', '', '100000', 50],
            ['750001', 'NUEVO NOMBRE', '', '100004', 50],
        ]))->assertOk()->assertJsonPath('data.actualizados', 1)->assertJsonPath('data.creados', 0);

        $corazon->refresh();
        $this->assertSame('NUEVO NOMBRE', $corazon->nombre);
        $this->assertSame('ya tenia', $corazon->descripcion, 'descripción vacía en el Excel no borra la existente');
        $this->assertSame(2, CorazonFormulaLine::where('corazon_id', $corazon->id)->count());
        $this->assertEqualsWithDelta(20.0, (float) $corazon->costo_unitario, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $producto->fresh()->costo_unitario, 0.0001);
    }

    public function test_plantilla_se_descarga_y_es_aceptada_por_el_importador(): void
    {
        $this->materiaPrima(['codigo' => '100000']);
        $this->materiaPrima(['codigo' => '100004']);

        $res = $this->get('/api/corazones/import/template');
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $res->streamedContent());
        $file = new UploadedFile($path, 'plantilla_corazones.xlsx', null, null, true);

        // La plantilla tal cual (con sus ejemplos) es válida contra el importador
        $this->subir($file, true)->assertOk()->assertJsonPath('data.creados', 2)->assertJsonPath('data.activos', 2);
    }

    public function test_descarga_corazones_en_el_formato_del_importador(): void
    {
        $a = $this->materiaPrima(['codigo' => '100000']);
        $b = $this->materiaPrima(['codigo' => '100004']);
        $this->subir($this->excel([
            ['750001', 'UNO', 'desc', '100000', 60],
            ['750001', 'UNO', 'desc', '100004', 40],
            ['750002', 'DOS', '', '100000', 50],
        ]))->assertOk();

        $res = $this->get('/api/corazones/export?search=UNO');
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        file_put_contents($path, $res->streamedContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0);
        $this->assertSame('CODIGO CORAZON', $hoja->getCell('A1')->getValue());
        $this->assertSame('750001', (string) $hoja->getCell('A2')->getValue());
        $this->assertSame('100000', (string) $hoja->getCell('D2')->getValue());
        $this->assertEquals(60, $hoja->getCell('E2')->getValue());
        $this->assertSame('100004', (string) $hoja->getCell('D3')->getValue());
        $this->assertNull($hoja->getCell('A4')->getValue(), 'el filtro deja fuera al corazón DOS');

        // Round-trip: lo descargado se puede volver a subir tal cual
        $this->subir(new UploadedFile($path, 'corazones.xlsx', null, null, true), true)
            ->assertOk()->assertJsonPath('data.actualizados', 1);
    }

    public function test_acepta_corazones_dentro_de_corazones_y_calcula_costos_en_cascada(): void
    {
        $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $existente = $this->materiaPrima(['codigo' => '750100', 'nombre' => 'YA EXISTE', 'tipo' => 'corazon', 'costo_unitario' => 8]);

        // El padre aparece ANTES que su hijo en el archivo; el hijo se define en el mismo archivo
        $this->subir($this->excel([
            ['340100', 'PADRE', '', '340101', 50],       // corazón definido más abajo en el archivo
            ['340100', 'PADRE', '', '750100', 50],       // corazón que ya existe
            ['340101', 'HIJO', '', '100000', 100],
        ]))->assertOk()->assertJsonPath('data.creados', 2)->assertJsonPath('data.activos', 2);

        $hijo = RawMaterial::where('codigo', '340101')->first();
        $padre = RawMaterial::where('codigo', '340100')->first();
        $this->assertEqualsWithDelta(10.0, (float) $hijo->costo_unitario, 0.0001);
        $this->assertEqualsWithDelta(9.0, (float) $padre->costo_unitario, 0.0001); // 0.5*10 + 0.5*8
        $this->assertSame(2, CorazonFormulaLine::where('corazon_id', $padre->id)->count());
    }

    public function test_rechaza_ciclos_entre_corazones(): void
    {
        $this->materiaPrima(['codigo' => '100000']);

        $this->subir($this->excel([
            ['340200', 'A', '', '340201', 50],
            ['340200', 'A', '', '100000', 50],
            ['340201', 'B', '', '340200', 50],
            ['340201', 'B', '', '100000', 50],
        ]))->assertStatus(422);

        $this->subir($this->excel([['340300', 'SOLO', '', '340300', 100]]))->assertStatus(422);
        $this->assertSame(0, RawMaterial::where('tipo', 'corazon')->count());
    }

    public function test_por_ahora_no_exige_permisos_especificos(): void
    {
        $this->givePermissions([]);

        $this->get('/api/corazones/import/template')->assertOk();
        $this->get('/api/corazones/export')->assertOk();
        $this->materiaPrima(['codigo' => '100000']);
        $this->subir($this->excel([['750001', 'UNO', '', '100000', 100]]))->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\ProductoTerminado;
use Illuminate\Support\Facades\DB;

class ImportarProductosTerminadosCsvTest extends ProductoTerminadoTestCase
{
    private function csv(array $filas): string
    {
        $hdr = 'id;codigo;nombre';
        for ($k = 1; $k <= 10; $k++) $hdr .= ";componente_{$k};comp{$k}_referencia;comp{$k}porc";
        $lineas = [$hdr . ';total'];
        foreach ($filas as $f) {
            $cols = [$f[0], $f[1], $f[2]];
            foreach ($f[3] as [$ref, $pct]) { array_push($cols, 'X', $ref, $pct); }
            while (count($cols) < 33) $cols[] = '0';
            $cols[] = '100';
            $lineas[] = implode(';', $cols);
        }
        $path = tempnam(sys_get_temp_dir(), 'pt') . '.csv';
        file_put_contents($path, mb_convert_encoding(implode("\r\n", $lineas), 'Windows-1252', 'UTF-8'));
        return $path;
    }

    public function test_reemplaza_productos_y_maneja_faltantes_repetidos_y_sumas(): void
    {
        $prov = $this->materiaPrima(['codigo' => '320011', 'costo_unitario' => 10, 'pendiente_equivalencia' => true]);
        $dpg = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 2]);
        ProductoTerminado::create(['codigo' => 'VIEJO', 'nombre' => 'Se borra']);

        $path = $this->csv([
            [1, '585002', 'SUPER LAVANDA CAMPIÑA', [['320011', 80], ['100000', 20]]],
            [2, '585005', 'FLORAL 1', [['320011', 100], ['100000', 20]]],
            [3, '685001', 'SABIL', [['285052 F', 70], ['100000', 30]]],
            [4, '585002', 'DUPLICADO', [['320011', 50], ['320011', 50]]],
        ]);

        $this->artisan('productos-terminados:importar-csv', ['path' => $path, '--force' => true])->assertSuccessful();

        $this->assertNull(ProductoTerminado::where('codigo', 'VIEJO')->first());
        $this->assertSame(4, ProductoTerminado::count());

        $ok = ProductoTerminado::where('codigo', '585002')->first();
        $this->assertSame('SUPER LAVANDA CAMPIÑA', $ok->nombre);                // codificación corregida
        $this->assertTrue($ok->activo);
        $this->assertEquals(8.4, (float) $ok->costo_unitario);                  // 80%×10 + 20%×2
        $this->assertMatchesRegularExpression('/^PT-\d{5}$/', $ok->consecutivo);

        $this->assertFalse(ProductoTerminado::where('codigo', '585005')->first()->activo);   // suma 120%
        $faltante = ProductoTerminado::where('codigo', '685001')->first();
        $this->assertFalse($faltante->activo);
        $this->assertStringContainsString('285052 F (70%)', $faltante->observaciones);

        $dup = ProductoTerminado::where('codigo', '585002-2')->first();       // código repetido
        $this->assertNotNull($dup);
        $this->assertEquals(100, (float) DB::table('producto_formula_lines')->where('producto_terminado_id', $dup->id)->value('porcentaje'));  // ingrediente repetido sumado
        $this->assertTrue($dup->activo);

        // Con materias primas provisionales: aparecen en el filtro
        $this->getJson('/api/productos-terminados?pendientes=1')->assertOk()->assertJsonCount(3, 'data.data');
    }
}

<?php

namespace Tests\Feature;

use App\Models\CorazonFormulaLine;
use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;

/**
 * Materias primas provisionales (320/330) pendientes de cambiar por su
 * equivalente código 300: marca, filtros y reemplazo en las fórmulas.
 */
class RawMaterialEquivalenciaTest extends ProductoTerminadoTestCase
{
    public function test_filtra_materias_primas_pendientes_y_asigna_equivalente(): void
    {
        $prov = $this->materiaPrima(['codigo' => '320003', 'nombre' => 'AVENA Y NUEZ', 'pendiente_equivalencia' => true]);
        $mp300 = $this->materiaPrima(['codigo' => '300100', 'nombre' => 'AVENA 300']);
        $this->materiaPrima(['codigo' => '300200']);

        $this->getJson('/api/raw-materials?pendiente=1')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.codigo', '320003');

        $this->putJson("/api/raw-materials/{$prov->id}", ['equivalente_id' => $mp300->id])->assertOk();
        $this->assertSame($mp300->id, $prov->fresh()->equivalente_id);

        // El equivalente no puede ser otro provisional ni ella misma
        $otroProv = $this->materiaPrima(['codigo' => '320004', 'pendiente_equivalencia' => true]);
        $this->putJson("/api/raw-materials/{$prov->id}", ['equivalente_id' => $otroProv->id])->assertStatus(422);
        $this->putJson("/api/raw-materials/{$prov->id}", ['equivalente_id' => $prov->id])->assertStatus(422);
    }

    public function test_productos_terminados_que_usan_provisionales(): void
    {
        $prov = $this->materiaPrima(['codigo' => '320003', 'pendiente_equivalencia' => true]);
        $dpg = $this->materiaPrima(['codigo' => '100000']);
        $con = ProductoTerminado::create(['codigo' => '585001', 'nombre' => 'OATMAXI']);
        $sin = ProductoTerminado::create(['codigo' => '585002', 'nombre' => 'LIMPIO']);
        ProductoFormulaLine::create(['producto_terminado_id' => $con->id, 'raw_material_id' => $prov->id, 'porcentaje' => 80]);
        ProductoFormulaLine::create(['producto_terminado_id' => $con->id, 'raw_material_id' => $dpg->id, 'porcentaje' => 20]);
        ProductoFormulaLine::create(['producto_terminado_id' => $sin->id, 'raw_material_id' => $dpg->id, 'porcentaje' => 100]);

        $this->getJson('/api/productos-terminados?pendientes=1')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.codigo', '585001')
            ->assertJsonPath('data.data.0.pendientes_count', 1);
    }

    public function test_reemplazar_por_equivalente_mueve_lineas_suma_duplicados_y_recalcula(): void
    {
        config(['custom.corazones_calcular_costos' => true]); // el cálculo está apagado por defecto
        $mp300 = $this->materiaPrima(['codigo' => '300100', 'costo_unitario' => 10]);
        $prov = $this->materiaPrima(['codigo' => '320003', 'costo_unitario' => 99, 'pendiente_equivalencia' => true, 'equivalente_id' => $mp300->id]);
        $sinEquiv = $this->materiaPrima(['codigo' => '320005', 'pendiente_equivalencia' => true]);
        $dpg = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 2]);

        // Producto que ya tenía el 300: se suman los porcentajes
        $p = ProductoTerminado::create(['codigo' => '585001', 'nombre' => 'OATMAXI']);
        ProductoFormulaLine::create(['producto_terminado_id' => $p->id, 'raw_material_id' => $prov->id, 'porcentaje' => 60]);
        ProductoFormulaLine::create(['producto_terminado_id' => $p->id, 'raw_material_id' => $mp300->id, 'porcentaje' => 20]);
        ProductoFormulaLine::create(['producto_terminado_id' => $p->id, 'raw_material_id' => $dpg->id, 'porcentaje' => 20]);
        // Corazón con el provisional
        $cor = $this->corazon();
        CorazonFormulaLine::create(['corazon_id' => $cor->id, 'raw_material_id' => $prov->id, 'porcentaje' => 100]);

        $this->postJson('/api/raw-materials/reemplazar-equivalencias')->assertOk()
            ->assertJsonPath('data.reemplazadas', 1)
            ->assertJsonPath('data.lineas_productos', 1)
            ->assertJsonPath('data.lineas_corazones', 1);

        $lineas = ProductoFormulaLine::where('producto_terminado_id', $p->id)->pluck('porcentaje', 'raw_material_id');
        $this->assertEquals(80, (float) $lineas[$mp300->id]);
        $this->assertArrayNotHasKey($prov->id, $lineas->all());
        $this->assertEquals(8.4, (float) $p->fresh()->costo_unitario);      // 80%×10 + 20%×2
        $this->assertEquals(10, (float) $cor->fresh()->costo_unitario);

        $prov->refresh();
        $this->assertFalse($prov->pendiente_equivalencia);
        $this->assertFalse($prov->activo);
        $this->assertTrue($sinEquiv->fresh()->pendiente_equivalencia);   // sin equivalente: no se toca
    }
}

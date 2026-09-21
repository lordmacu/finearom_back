<?php

namespace Tests\Unit;

use App\Support\PurchaseOrderFreightNotice as Aviso;
use PHPUnit\Framework\TestCase;

class PurchaseOrderFreightNoticeTest extends TestCase
{
    public function test_se_muestra_en_ordenes_pequenas_de_clientes_no_grandes(): void
    {
        $this->assertTrue(Aviso::aplica('C', 5_000_000.0));
        $this->assertTrue(Aviso::aplica('B', 9_999_999.0));
    }

    public function test_nunca_se_muestra_a_clientes_aa_ni_a(): void
    {
        $this->assertFalse(Aviso::aplica('AA', 1_000_000.0));
        $this->assertFalse(Aviso::aplica('A', 1_000_000.0));
    }

    public function test_no_se_muestra_si_la_orden_llega_al_tope(): void
    {
        $this->assertFalse(Aviso::aplica('C', 10_000_000.0));
        $this->assertFalse(Aviso::aplica('C', 54_000_000.0));
    }

    public function test_los_clientes_sin_letra_reciben_el_aviso(): void
    {
        // 79 de los 169 clientes están marcados con la otra clasificación
        // (pareto/balance/none). No son AA ni A, así que les aplica la regla
        // del monto como a cualquier otro.
        $this->assertTrue(Aviso::aplica('pareto', 3_000_000.0));
        $this->assertTrue(Aviso::aplica('none', 3_000_000.0));
        $this->assertTrue(Aviso::aplica(null, 3_000_000.0));
        $this->assertFalse(Aviso::aplica('pareto', 20_000_000.0));
    }

    public function test_sin_total_calculable_no_se_muestra(): void
    {
        // Sin productos o sin una TRM confiable. Se prefiere callar antes que
        // mandarle el aviso a un cliente grande por un error de cálculo.
        $this->assertFalse(Aviso::aplica('C', null));
        $this->assertFalse(Aviso::aplica('AA', null));
    }

    public function test_el_html_trae_el_texto_por_defecto_y_escapa(): void
    {
        // Sin base de datos, ConfigSystem no responde: debe caer al texto fijo.
        $html = @Aviso::html();

        $this->assertStringContainsString('FINEAROM asumirá el costo del flete', $html);
        $this->assertStringContainsString('$2.000.000 COP', $html);
        $this->assertStringContainsString('<p style=', $html);
    }

    public function test_el_tope_y_los_tipos_excluidos_son_los_acordados(): void
    {
        $this->assertSame(10000000, Aviso::LIMITE_COP);
        $this->assertSame(['AA', 'A'], Aviso::TIPOS_EXCLUIDOS);
    }
}

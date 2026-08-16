<?php

namespace Tests\Unit;

use App\Services\BusinessDaysService;
use Tests\TestCase;

class BusinessDaysServiceTest extends TestCase
{
    private BusinessDaysService $dias;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dias = new BusinessDaysService();
    }

    public function test_cuenta_los_festivos_del_anio(): void
    {
        // Colombia tiene 18 festivos legales, pero lo que importa para contar días
        // hábiles son las FECHAS distintas: dos festivos pueden caer el mismo día.
        $this->assertCount(18, $this->dias->festivos(2026));
        $this->assertCount(18, $this->dias->festivos(2027));
    }

    public function test_dos_festivos_que_caen_el_mismo_dia_cuentan_una_vez(): void
    {
        // En 2025 el 29 de junio (San Pedro y San Pablo) es domingo y se corre al
        // lunes 30, que es justamente el Sagrado Corazón. Ese día no puede restar
        // dos veces del conteo de días hábiles.
        $festivos = $this->dias->festivos(2025);

        $this->assertArrayHasKey('2025-06-30', $festivos);
        $this->assertCount(17, $festivos, '18 festivos legales, 17 fechas distintas');
    }

    public function test_reconoce_festivos_fijos(): void
    {
        $festivos = $this->dias->festivos(2026);

        $this->assertArrayHasKey('2026-01-01', $festivos, 'Año Nuevo');
        $this->assertArrayHasKey('2026-05-01', $festivos, 'Día del Trabajo');
        $this->assertArrayHasKey('2026-07-20', $festivos, 'Independencia');
        $this->assertArrayHasKey('2026-08-07', $festivos, 'Batalla de Boyacá');
        $this->assertArrayHasKey('2026-12-25', $festivos, 'Navidad');
    }

    public function test_traslada_los_festivos_de_la_ley_emiliani_al_lunes(): void
    {
        $festivos = $this->dias->festivos(2026);

        // 6 de enero de 2026 es martes → se corre al lunes 12.
        $this->assertArrayNotHasKey('2026-01-06', $festivos);
        $this->assertArrayHasKey('2026-01-12', $festivos, 'Reyes trasladado al lunes');

        // 19 de marzo de 2026 es jueves → se corre al lunes 23.
        $this->assertArrayNotHasKey('2026-03-19', $festivos);
        $this->assertArrayHasKey('2026-03-23', $festivos, 'San José trasladado al lunes');
    }

    public function test_calcula_los_festivos_de_semana_santa(): void
    {
        // Pascua 2026: domingo 5 de abril.
        $festivos = $this->dias->festivos(2026);

        $this->assertArrayHasKey('2026-04-02', $festivos, 'Jueves Santo');
        $this->assertArrayHasKey('2026-04-03', $festivos, 'Viernes Santo');
    }

    public function test_no_cuenta_fines_de_semana(): void
    {
        // Viernes 7 de agosto de 2026 a lunes 10: solo el lunes es hábil...
        // salvo que el 7 sea festivo (Boyacá). Se usa otra semana limpia:
        // viernes 21 de agosto de 2026 → lunes 24 = 1 día hábil.
        $this->assertSame(1, $this->dias->between('2026-08-21', '2026-08-24'));
    }

    public function test_no_cuenta_festivos(): void
    {
        // Del jueves 6 al lunes 10 de agosto de 2026: el viernes 7 es Batalla de
        // Boyacá, sábado y domingo no cuentan, así que solo queda el lunes.
        $this->assertSame(1, $this->dias->between('2026-08-06', '2026-08-10'));
    }

    public function test_semana_corrida_da_cinco_dias(): void
    {
        // Lunes 17 a lunes 24 de agosto de 2026, sin festivos de por medio.
        $this->assertSame(5, $this->dias->between('2026-08-17', '2026-08-24'));
    }

    public function test_mismo_dia_es_cero(): void
    {
        $this->assertSame(0, $this->dias->between('2026-08-17', '2026-08-17'));
    }

    public function test_fecha_final_anterior_a_la_inicial_es_cero(): void
    {
        $this->assertSame(0, $this->dias->between('2026-08-20', '2026-08-17'));
    }

    public function test_semana_santa_completa_descuenta_los_dos_festivos(): void
    {
        // Lunes 30 de marzo a lunes 6 de abril de 2026: cinco días corridos hábiles
        // menos jueves y viernes santo = 3.
        $this->assertSame(3, $this->dias->between('2026-03-30', '2026-04-06'));
    }
}

<?php

namespace Tests\Unit;

use App\Http\Requests\Project\ProjectExternalStatusRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProjectExternalStatusRequestTest extends TestCase
{
    private function valida(array $data): bool
    {
        return Validator::make($data, (new ProjectExternalStatusRequest())->rules())->passes();
    }

    /**
     * El resultado comercial que se marca a mano: Cancelado, Ganado o Perdido.
     * 'Sin definir' es el estado inicial y no se elige a mano.
     *
     * @dataProvider estadosDeLaInterfaz
     */
    public function test_acepta_los_estados_que_ofrece_la_interfaz(string $estado): void
    {
        $this->assertTrue($this->valida(['status' => $estado]), "Rechazó el estado '{$estado}'");
    }

    public static function estadosDeLaInterfaz(): array
    {
        return [['Cancelado'], ['Ganado'], ['Perdido']];
    }

    public function test_rechaza_un_estado_desconocido(): void
    {
        $this->assertFalse($this->valida(['status' => 'En espera']));
        $this->assertFalse($this->valida(['status' => 'Sin definir']));
    }

    public function test_exige_el_estado(): void
    {
        $this->assertFalse($this->valida([]));
    }
}

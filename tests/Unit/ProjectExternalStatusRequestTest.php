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
     * El menú de estado externo de ProjectShow ofrece estos tres valores y
     * 'En espera' es además el estado inicial de todo proyecto.
     *
     * @dataProvider estadosDeLaInterfaz
     */
    public function test_acepta_los_estados_que_ofrece_la_interfaz(string $estado): void
    {
        $this->assertTrue($this->valida(['status' => $estado]), "Rechazó el estado '{$estado}'");
    }

    public static function estadosDeLaInterfaz(): array
    {
        return [['En espera'], ['Ganado'], ['Perdido']];
    }

    public function test_rechaza_un_estado_desconocido(): void
    {
        $this->assertFalse($this->valida(['status' => 'Cancelado']));
    }

    public function test_exige_el_estado(): void
    {
        $this->assertFalse($this->valida([]));
    }
}

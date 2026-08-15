<?php

namespace Tests\Unit;

use App\Mail\ClientWelcomeMail;
use App\Models\Client;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El correo interno que sale cuando un cliente completa el formulario público
 * debe avisarle al equipo qué campos de manejo interno quedaron pendientes,
 * porque el cliente no puede llenarlos.
 */
class ClientWelcomeInternalNoticeTest extends TestCase
{
    private function aviso(Client $client): string
    {
        $mail = new ClientWelcomeMail($client, [], false, true);
        $metodo = new ReflectionMethod($mail, 'buildInternalFieldsNotice');
        $metodo->setAccessible(true);

        return $metodo->invoke($mail);
    }

    private function cliente(array $atributos): Client
    {
        $client = new Client();
        $client->id = 123;
        foreach ($atributos as $k => $v) {
            $client->{$k} = $v;
        }

        return $client;
    }

    public function test_marca_como_pendientes_los_campos_vacios(): void
    {
        $html = $this->aviso($this->cliente([
            'credit_term' => null,
            'client_type' => '',
            'lead_time'   => null,
        ]));

        $this->assertStringContainsString('Faltan 3 de 3 campos internos', $html);
        $this->assertStringContainsString('Plazo de crédito', $html);
        $this->assertStringContainsString('Tipo de cliente', $html);
        $this->assertStringContainsString('Lead time', $html);
        $this->assertSame(3, substr_count($html, 'Pendiente por completar'));
    }

    public function test_muestra_el_valor_de_los_campos_ya_completos(): void
    {
        $html = $this->aviso($this->cliente([
            'credit_term' => 30,
            'client_type' => 'pareto',
            'lead_time'   => 9,
        ]));

        $this->assertStringContainsString('ya están completos', $html);
        $this->assertStringNotContainsString('Pendiente por completar', $html);
        $this->assertStringContainsString('30', $html);
        $this->assertStringContainsString('pareto', $html);
        $this->assertStringContainsString('9', $html);
    }

    public function test_cuenta_solo_los_que_faltan(): void
    {
        $html = $this->aviso($this->cliente([
            'credit_term' => 45,
            'client_type' => null,
            'lead_time'   => null,
        ]));

        $this->assertStringContainsString('Faltan 2 de 3 campos internos', $html);
        $this->assertSame(2, substr_count($html, 'Pendiente por completar'));
        $this->assertStringContainsString('45', $html);
    }

    public function test_incluye_el_enlace_para_completar_en_la_plataforma(): void
    {
        $html = $this->aviso($this->cliente(['credit_term' => null]));

        $this->assertStringContainsString('/clients/123/edit', $html);
        $this->assertStringContainsString('Completar en la plataforma', $html);
    }

    public function test_el_aviso_no_se_agrega_si_no_es_la_copia_interna(): void
    {
        $mail = new ClientWelcomeMail($this->cliente(['credit_term' => null]), [], false, false);

        $this->assertFalse($mail->internalNotice, 'Sin la bandera, el correo al cliente no debe llevar el aviso');
    }
}

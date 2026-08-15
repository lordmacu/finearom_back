<?php

namespace Tests\Unit;

use App\Http\Controllers\ClientController;
use ReflectionClass;
use Tests\TestCase;

/**
 * El formulario público de autocreación lo llena el cliente, pero hay campos que
 * son de manejo interno de Finearom y no deben mostrársele ni aceptarse desde ahí.
 *
 * Estas pruebas fijan esa frontera: son la red que avisa si alguien agrega o quita
 * un campo de la lista sin darse cuenta de las dos puntas (vista y endpoint).
 */
class ClientInternalFieldsTest extends TestCase
{
    /** @return string[] */
    private function internalOnlyFields(): array
    {
        $const = (new ReflectionClass(ClientController::class))
            ->getConstant('CLIENT_INTERNAL_ONLY_FIELDS');

        return is_array($const) ? $const : [];
    }

    public function test_los_tres_campos_internos_estan_declarados(): void
    {
        $campos = $this->internalOnlyFields();

        $this->assertContains('credit_term', $campos, 'El plazo de crédito es interno');
        $this->assertContains('client_type', $campos, 'El tipo de cliente es interno');
        $this->assertContains('lead_time', $campos, 'El lead time es interno');
    }

    public function test_los_campos_que_llena_el_cliente_no_estan_bloqueados(): void
    {
        $campos = $this->internalOnlyFields();

        $this->assertNotContains('billing_closure_date', $campos, 'La fecha de cierre de facturación la llena el cliente');
        $this->assertNotContains('billing_closure', $campos, 'La fecha de cierre de facturación la llena el cliente');
        $this->assertNotContains('taxpayer_type', $campos, 'El tipo de contribuyente lo llena el cliente');
    }

    public function test_el_filtro_descarta_lo_interno_y_conserva_lo_demas(): void
    {
        // Payload como el que llegaría del formulario público con los campos
        // internos inyectados a mano.
        $payload = [
            'client_name'         => 'Cliente de prueba',
            'email'               => 'cliente@ejemplo.com',
            'billing_closure_date' => '2026-08-31',
            'taxpayer_type'       => 'gran_contribuyente',
            'credit_term'         => 999,
            'client_type'         => 'pareto',
            'lead_time'           => 1,
        ];

        $filtrado = collect($payload)->except($this->internalOnlyFields())->all();

        $this->assertArrayNotHasKey('credit_term', $filtrado);
        $this->assertArrayNotHasKey('client_type', $filtrado);
        $this->assertArrayNotHasKey('lead_time', $filtrado);

        $this->assertSame('Cliente de prueba', $filtrado['client_name']);
        $this->assertSame('cliente@ejemplo.com', $filtrado['email']);
        $this->assertSame('2026-08-31', $filtrado['billing_closure_date']);
        $this->assertSame('gran_contribuyente', $filtrado['taxpayer_type']);
    }

    public function test_la_vista_publica_oculta_y_no_envia_los_mismos_campos(): void
    {
        $ruta = base_path('../frontend/src/components/clients/ClientForm.vue');
        if (! is_readable($ruta)) {
            $this->markTestSkipped('El repo del frontend no está junto al del backend en este entorno.');
        }

        $vista = file_get_contents($ruta);

        // Los tres campos internos deben estar detrás de `v-if="!isExternalMode"`.
        foreach (['form.credit_term', 'form.client_type', 'form.lead_time'] as $binding) {
            $pos = strpos($vista, $binding);
            $this->assertNotFalse($pos, "No se encontró el binding {$binding} en la vista");

            $bloqueAnterior = substr($vista, max(0, $pos - 400), 400);
            $this->assertStringContainsString(
                'v-if="!isExternalMode"',
                $bloqueAnterior,
                "El campo {$binding} debe ocultarse en el formulario público"
            );
        }

        // Y deben quitarse del payload antes de enviarlo.
        foreach ($this->internalOnlyFields() as $campo) {
            $this->assertMatchesRegularExpression(
                "/INTERNAL_ONLY_FIELDS\s*=\s*\[[^\]]*'{$campo}'/",
                $vista,
                "El campo {$campo} debe excluirse del payload del formulario público"
            );
        }
    }
}

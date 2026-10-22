<?php

namespace Tests\Unit;

use Tests\TestCase;

class CampaignHeaderViewTest extends TestCase
{
    private function render(array $extra): string
    {
        return view('emails.template', array_merge([
            'subject' => 'S', 'title' => 'Campaña Finearom', 'header_content' => '<p>Cuerpo</p>',
            'footer_content' => '', 'signature' => '', 'plant_observations' => '',
        ], $extra))->render();
    }

    public function test_con_encabezado_lleva_logo_y_titulo(): void
    {
        $html = $this->render([]);
        $this->assertStringContainsString('images/logo.png', $html);
        $this->assertStringContainsString('Campaña Finearom', $html);
    }

    public function test_sin_encabezado_no_lleva_logo_ni_titulo(): void
    {
        $html = $this->render(['show_header' => false, 'title' => null]);
        $this->assertStringNotContainsString('images/logo.png', $html);
        $this->assertStringNotContainsString('Campaña Finearom', $html);
        $this->assertStringContainsString('Cuerpo', $html);
    }
}

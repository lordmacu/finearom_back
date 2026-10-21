<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\CampaignSignature;
use PHPUnit\Framework\TestCase;

class CampaignSignatureTest extends TestCase
{
    public function test_agrega_la_firma_del_usuario_al_final(): void
    {
        $u = new User();
        $u->campaign_signature = '<p>Francy</p>';

        $this->assertStringEndsWith('<p>Francy</p></div>', CampaignSignature::append('<p>Hola</p>', $u));
        $this->assertStringStartsWith('<p>Hola</p>', CampaignSignature::append('<p>Hola</p>', $u));
    }

    public function test_sin_firma_o_sin_usuario_el_cuerpo_queda_igual(): void
    {
        $this->assertSame('<p>Hola</p>', CampaignSignature::append('<p>Hola</p>', new User()));
        $this->assertSame('<p>Hola</p>', CampaignSignature::append('<p>Hola</p>', null));
    }

    public function test_sanitize_quita_scripts_y_manejadores(): void
    {
        $limpio = CampaignSignature::sanitize('<p onclick="x()">a</p><script>alert(1)</script><a href="javascript:evil()">l</a><img src="u" onerror="y()">');

        $this->assertStringNotContainsString('script', $limpio);
        $this->assertStringNotContainsString('onclick', $limpio);
        $this->assertStringNotContainsString('onerror', $limpio);
        $this->assertStringNotContainsString('javascript:', $limpio);
        $this->assertStringContainsString('<p>a</p>', $limpio);
    }
}

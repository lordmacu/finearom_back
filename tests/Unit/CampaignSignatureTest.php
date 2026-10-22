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

    public function test_la_tabla_de_la_firma_toma_el_diseno_corporativo(): void
    {
        $firma = '<figure class="table"><table><tbody><tr><td><img src="l.png"></td><td><strong>Ana</strong><br>Cargo</td><td>Tel<br>mail</td></tr></tbody></table></figure>';
        $html = CampaignSignature::paraCorreo($firma);

        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringContainsString('border:none', $html);
        $this->assertSame(1, substr_count($html, 'border-left:1px solid #2f5fb3'));   // solo antes del contacto
        $this->assertStringContainsString('border-bottom:1px solid #2f5fb3', $html);  // línea final
        $this->assertStringContainsString('<strong>Ana</strong>', $html);
        $this->assertStringContainsString('Cargo', $html);
    }

    public function test_una_firma_sin_tabla_queda_igual(): void
    {
        $this->assertSame('<p>Ana<br>Cargo</p>', CampaignSignature::paraCorreo('<p>Ana<br>Cargo</p>'));
    }

    public function test_conserva_acentos(): void
    {
        $html = CampaignSignature::paraCorreo('<table><tbody><tr><td>Gestión</td><td>Teléfono ✉</td></tr></tbody></table>');
        $this->assertStringContainsString('Gestión', $html);
        $this->assertStringContainsString('Teléfono', $html);
    }
}

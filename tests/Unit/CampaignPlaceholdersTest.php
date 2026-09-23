<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Support\CampaignPlaceholders as PH;
use PHPUnit\Framework\TestCase;

class CampaignPlaceholdersTest extends TestCase
{
    private function cliente(array $attrs = []): Client
    {
        // Sin base de datos: basta con un modelo en memoria.
        return new Client(array_merge([
            'client_name'             => 'MAZIVO GROUP S.A.S.',
            'nit'                     => '900220672-8',
            'city'                    => 'Medellín',
            'address'                 => 'Carrera 43 # 1-50',
            'executive'               => 'carolina.uribe',
            'purchasing_contact_name' => 'Ana Gómez',
        ], $attrs));
    }

    public function test_reemplaza_company_y_nit(): void
    {
        $html = '<p>Estimados |company|, con NIT |nit|.</p>';

        $this->assertSame(
            '<p>Estimados MAZIVO GROUP S.A.S., con NIT 900220672-8.</p>',
            PH::replace($html, $this->cliente()),
        );
    }

    public function test_reemplaza_la_ejecutiva(): void
    {
        $this->assertSame('Atte. carolina.uribe', PH::replace('Atte. |ejecutiva|', $this->cliente()));
    }

    /**
     * Ciudad, dirección y contacto están desactivados por ahora (esos campos
     * vienen vacíos en buena parte de los clientes). Mientras lo estén, el
     * texto se deja intacto en vez de reemplazarlo por nada.
     */
    public function test_los_placeholders_desactivados_no_se_reemplazan(): void
    {
        $this->assertSame(
            '|ciudad| |direccion| |contacto|',
            PH::replace('|ciudad| |direccion| |contacto|', $this->cliente()),
        );

        $this->assertSame(['company', 'nit', 'ejecutiva'], array_keys(PH::MAPA));
    }

    /**
     * El caso que importa: cada destinatario recibe SUS datos. Si el reemplazo
     * se hiciera una sola vez sobre la campaña, el segundo cliente vería el
     * nombre y el NIT del primero.
     */
    public function test_cada_cliente_recibe_solo_sus_propios_datos(): void
    {
        $plantilla = 'Hola |company| (|nit|)';

        $uno = PH::replace($plantilla, $this->cliente());
        $dos = PH::replace($plantilla, $this->cliente([
            'client_name' => 'OTRA EMPRESA LTDA',
            'nit'         => '800111222-3',
        ]));

        $this->assertSame('Hola MAZIVO GROUP S.A.S. (900220672-8)', $uno);
        $this->assertSame('Hola OTRA EMPRESA LTDA (800111222-3)', $dos);

        // Ninguno arrastra nada del otro.
        $this->assertStringNotContainsString('OTRA EMPRESA', $uno);
        $this->assertStringNotContainsString('800111222-3', $uno);
        $this->assertStringNotContainsString('MAZIVO', $dos);
        $this->assertStringNotContainsString('900220672-8', $dos);
    }

    public function test_sin_cliente_usa_texto_neutro(): void
    {
        $this->assertSame('Estimados cliente,', PH::replace('Estimados |company|,', null));
        $this->assertSame('NIT: ', PH::replace('NIT: |nit|', null));
    }

    public function test_campo_vacio_en_el_cliente_no_deja_el_placeholder_visible(): void
    {
        $c = $this->cliente(['executive' => null, 'nit' => '']);

        $salida = PH::replace('[|ejecutiva|][|nit|]', $c);

        $this->assertSame('[][]', $salida);
        $this->assertStringNotContainsString('|', $salida);
    }

    public function test_no_toca_el_html_que_no_es_placeholder(): void
    {
        $html = '<a href="https://ordenes.finearom.co/x?a=1|2">ver</a> costo: 10|20';

        $this->assertSame($html, PH::replace($html, $this->cliente()));
    }

    public function test_texto_vacio_o_nulo(): void
    {
        $this->assertSame('', PH::replace('', $this->cliente()));
        $this->assertSame('', PH::replace(null, $this->cliente()));
    }

    public function test_el_correo_de_prueba_usa_valores_de_muestra(): void
    {
        $salida = PH::replaceWithSample('|company| — |nit| — |ejecutiva|');

        $this->assertSame('CLIENTE DE PRUEBA S.A.S. — 900.123.456-7 — Ejecutiva de prueba', $salida);
        $this->assertStringNotContainsString('|', $salida);

        // La muestra sigue al MAPA: si un placeholder está desactivado, la
        // prueba tampoco lo reemplaza.
        $this->assertSame(array_keys(PH::MAPA), array_keys(PH::sampleValues()));
    }

    public function test_el_catalogo_trae_los_botones_del_formulario(): void
    {
        $cat = PH::catalogo();

        $this->assertSame('|company|', $cat[0]['placeholder']);
        $this->assertSame('Nombre del cliente', $cat[0]['label']);
        $this->assertCount(count(PH::MAPA), $cat);

        foreach ($cat as $item) {
            $this->assertMatchesRegularExpression('/^\|[a-z]+\|$/', $item['placeholder']);
        }
    }
}

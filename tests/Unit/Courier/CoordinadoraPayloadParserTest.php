<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\CoordinadoraPayloadParser;
use App\Services\Courier\CourierEvent;
use App\Services\Courier\CourierStatus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CoordinadoraPayloadParserTest extends TestCase
{
    private CoordinadoraPayloadParser $parser;

    /** JSON de ejemplo tal como lo documenta Coordinadora para Push Tracking v3. */
    private const EJEMPLO_TRACKING = [
        'tracking_number'     => '30380000550',
        'referencia'          => 'REF-001',
        'comment'             => 'ENTREGADA',
        'codigo'              => '6',
        'codigo_cliente'      => 'CLI-01',
        'fecha'               => '2026-08-10',
        'hora'                => '13:51:43',
        'anterior'            => '',
        'referencia_anterior' => '',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CoordinadoraPayloadParser();
    }

    private function envelope(array $payload): array
    {
        return [
            'message' => [
                'data' => base64_encode(json_encode($payload)),
            ],
        ];
    }

    // --- decodeTrackingEnvelope ---------------------------------------

    public function test_decodifica_la_envoltura_pub_sub_del_ejemplo_de_la_documentacion(): void
    {
        $body = $this->envelope(self::EJEMPLO_TRACKING);

        $resultado = $this->parser->decodeTrackingEnvelope($body);

        $this->assertIsArray($resultado);
        $this->assertSame('30380000550', $resultado['tracking_number']);
        $this->assertSame('ENTREGADA', $resultado['comment']);
        $this->assertSame('6', $resultado['codigo']);
    }

    public function test_envoltura_sin_la_clave_message_devuelve_null(): void
    {
        $this->assertNull($this->parser->decodeTrackingEnvelope([]));
        $this->assertNull($this->parser->decodeTrackingEnvelope(['otra_cosa' => 'x']));
    }

    public function test_envoltura_con_message_que_no_es_arreglo_devuelve_null(): void
    {
        $this->assertNull($this->parser->decodeTrackingEnvelope(['message' => 'no soy un arreglo']));
    }

    public function test_data_que_no_es_base64_valido_devuelve_null(): void
    {
        $body = ['message' => ['data' => '@@@ no es base64 @@@']];

        $this->assertNull($this->parser->decodeTrackingEnvelope($body));
    }

    public function test_data_sin_la_clave_devuelve_null(): void
    {
        $this->assertNull($this->parser->decodeTrackingEnvelope(['message' => []]));
    }

    public function test_data_que_decodifica_a_algo_que_no_es_json_devuelve_null(): void
    {
        $body = ['message' => ['data' => base64_encode('esto no es json')]];

        $this->assertNull($this->parser->decodeTrackingEnvelope($body));
    }

    public function test_data_que_decodifica_a_json_que_no_es_objeto_ni_arreglo_devuelve_null(): void
    {
        $body = ['message' => ['data' => base64_encode('"solo un string json"')]];

        $this->assertNull($this->parser->decodeTrackingEnvelope($body));
    }

    // --- mapTrackingStatus ----------------------------------------------

    public function test_comment_entregada_mapea_a_entregado(): void
    {
        $estado = $this->parser->mapTrackingStatus(['comment' => 'ENTREGADA', 'codigo' => '6']);

        $this->assertSame(CourierStatus::ENTREGADO, $estado);
    }

    public function test_comment_entregado_minusculas_y_con_tildes_mapea_a_entregado(): void
    {
        $estado = $this->parser->mapTrackingStatus(['comment' => 'entregádo al cliente']);

        $this->assertSame(CourierStatus::ENTREGADO, $estado);
    }

    public function test_desc_estado_en_reparto_mapea_a_en_transito(): void
    {
        $estado = $this->parser->mapTrackingStatus([
            'codigo'       => '801',
            'codigo_estado' => '5',
            'desc_estado'  => 'EN REPARTO',
        ]);

        $this->assertSame(CourierStatus::EN_TRANSITO, $estado);
    }

    public function test_comment_pedido_cancelado_mapea_a_novedad(): void
    {
        $estado = $this->parser->mapTrackingStatus(['comment' => 'Pedido Cancelado']);

        $this->assertSame(CourierStatus::NOVEDAD, $estado);
    }

    public function test_devolucion_con_tilde_mapea_a_devuelto(): void
    {
        $estado = $this->parser->mapTrackingStatus(['comment' => 'DEVOLUCIÓN AL REMITENTE']);

        $this->assertSame(CourierStatus::DEVUELTO, $estado);
    }

    public function test_devuelta_sin_tilde_mapea_a_devuelto(): void
    {
        $estado = $this->parser->mapTrackingStatus(['desc_estado' => 'MERCANCIA DEVUELTA']);

        $this->assertSame(CourierStatus::DEVUELTO, $estado);
    }

    public function test_codigo_y_descripcion_desconocidos_mapea_a_en_transito_y_no_revienta(): void
    {
        Log::shouldReceive('info')->once();

        $estado = $this->parser->mapTrackingStatus([
            'codigo'  => '999',
            'comment' => 'Estado nunca antes visto',
        ]);

        $this->assertSame(CourierStatus::EN_TRANSITO, $estado);
    }

    public function test_codigo_desconocido_registra_codigo_y_descripcion_en_el_log(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with(\Mockery::on(function (string $mensaje) {
                return str_contains($mensaje, '999') && str_contains($mensaje, 'Estado nunca antes visto');
            }));

        $this->parser->mapTrackingStatus([
            'codigo'  => '999',
            'comment' => 'Estado nunca antes visto',
        ]);
    }

    public function test_payload_sin_comment_ni_desc_estado_no_revienta(): void
    {
        Log::shouldReceive('info')->once();

        $estado = $this->parser->mapTrackingStatus(['codigo' => '1']);

        $this->assertSame(CourierStatus::EN_TRANSITO, $estado);
    }

    // --- toEvent ----------------------------------------------------------

    public function test_arma_el_evento_con_codigo_y_descripcion_desde_comment(): void
    {
        $evento = $this->parser->toEvent([
            'codigo'  => '6',
            'comment' => 'ENTREGADA',
            'fecha'   => '2026-08-10',
            'hora'    => '13:51:43',
        ]);

        $this->assertInstanceOf(CourierEvent::class, $evento);
        $this->assertSame('2026-08-10 13:51:43', $evento->occurredAt);
        $this->assertSame('6', $evento->code);
        $this->assertSame('ENTREGADA', $evento->description);
    }

    public function test_usa_desc_estado_cuando_no_hay_comment(): void
    {
        $evento = $this->parser->toEvent([
            'codigo'      => '801',
            'desc_estado' => 'EN REPARTO',
            'fecha'       => '2026-08-10',
            'hora'        => '09:00:00',
        ]);

        $this->assertSame('EN REPARTO', $evento->description);
    }

    public function test_hora_con_microsegundos_se_recorta_al_formato_exacto(): void
    {
        $evento = $this->parser->toEvent([
            'codigo'  => '6',
            'comment' => 'ENTREGADA',
            'fecha'   => '2026-08-10',
            'hora'    => '13:51:43.456818',
        ]);

        $this->assertSame('2026-08-10 13:51:43', $evento->occurredAt);
    }

    public function test_evento_conserva_el_payload_crudo(): void
    {
        $payload = [
            'codigo'  => '6',
            'comment' => 'ENTREGADA',
            'fecha'   => '2026-08-10',
            'hora'    => '13:51:43',
        ];

        $evento = $this->parser->toEvent($payload);

        $this->assertSame($payload, $evento->raw);
    }

    // --- mapNovedadStatus ---------------------------------------------

    public function test_mapNovedadStatus_siempre_devuelve_novedad(): void
    {
        $this->assertSame(CourierStatus::NOVEDAD, $this->parser->mapNovedadStatus([]));
        $this->assertSame(CourierStatus::NOVEDAD, $this->parser->mapNovedadStatus([
            'evento'              => 'reporte',
            'id_novedad'          => '12',
            'descripcion_novedad' => 'Dirección errada',
        ]));
    }

    // --- extractTrackingNumber -----------------------------------------

    public function test_extrae_la_guia_del_endpoint_tracking(): void
    {
        $numero = $this->parser->extractTrackingNumber('tracking', ['tracking_number' => '30380000550']);

        $this->assertSame('30380000550', $numero);
    }

    public function test_extrae_la_guia_del_endpoint_novedades(): void
    {
        $numero = $this->parser->extractTrackingNumber('novedades', ['numero_guia' => '30380000551']);

        $this->assertSame('30380000551', $numero);
    }

    public function test_extrae_la_guia_del_endpoint_soluciones(): void
    {
        $numero = $this->parser->extractTrackingNumber('soluciones', ['numero_guia' => '30380000552']);

        $this->assertSame('30380000552', $numero);
    }

    public function test_extraccion_de_guia_sin_la_clave_esperada_devuelve_null(): void
    {
        $this->assertNull($this->parser->extractTrackingNumber('tracking', ['numero_guia' => '30380000550']));
        $this->assertNull($this->parser->extractTrackingNumber('novedades', ['tracking_number' => '30380000550']));
        $this->assertNull($this->parser->extractTrackingNumber('tracking', []));
    }
}

<?php

namespace App\Services;

use App\Models\Client;
use Carbon\Carbon;

/**
 * Reglas de cálculo de la proforma.
 *
 * - Retenciones (ReteICA, ReteIVA, Retefuente) solo si el subtotal de productos
 *   supera RETENCIONES_DESDE (COP); los porcentajes salen del cliente (ubicación,
 *   tipo de contribuyente, zona franca).
 * - Flete: solo se puede diligenciar si el subtotal es menor a FLETE_HASTA.
 * - Nacional (CO) va en pesos con la TRM del día; exportación en USD.
 * - Validez: VALIDEZ_DIAS_HABILES días hábiles desde la emisión.
 */
class ProformaService
{
    public const RETENCIONES_DESDE = 524000;
    public const FLETE_HASTA = 2000000;
    public const VALIDEZ_DIAS_HABILES = 8;
    /** Días hábiles restantes (o menos) en los que se avisa que está por vencer. */
    public const AVISO_DIAS_HABILES = 2;

    public const CUENTA_BANCARIA = 'ITAU COLOMBIA SA, cuenta corriente # 007455090';

    public function __construct(private readonly TrmService $trmService) {}

    public function esNacional(?string $country): bool
    {
        return ($country ?: 'CO') === 'CO';
    }

    /** TRM del día de la fecha dada (la serie oficial de Banrep). */
    public function trmDelDia(?string $date = null): float
    {
        return $this->trmService->getTrm($date ?: now()->format('Y-m-d'));
    }

    /**
     * @param  array<int, array{code: string, description: string, quantity: float|int, price_usd: float|int}>  $lines
     * @return array{items: array, totals: array, flete_aplica: bool, retenciones_aplican: bool}
     */
    public function calcular(Client $client, array $lines, string $country, float $trm, float $flete = 0): array
    {
        $nacional = $this->esNacional($country);

        $items = [];
        $subtotal = 0.0;
        $idx = 1;
        foreach ($lines as $line) {
            $unitPrice = $nacional ? round($line['price_usd'] * $trm, 2) : (float) $line['price_usd'];
            $total = round($line['quantity'] * $unitPrice, 2);
            $subtotal += $total;

            $items[] = [
                'item' => $idx++,
                'code' => $line['code'],
                'description' => $line['description'],
                'unit' => 'KG',
                'quantity' => $line['quantity'],
                'unit_price' => $unitPrice,
                'total' => $total,
            ];
        }

        // El flete solo aplica a pedidos pequeños y a proformas en pesos
        $fleteAplica = $nacional && $subtotal < self::FLETE_HASTA;
        $flete = $fleteAplica ? max(0, $flete) : 0.0;

        $base = $subtotal + $flete;
        $totals = [
            'subtotal' => $subtotal,
            'flete' => $flete,
            'iva' => 0.0,
            'rete_ica' => 0.0,
            'rete_iva' => 0.0,
            'rete_fte' => 0.0,
            'total' => $base,
        ];

        $retencionesAplican = $nacional && $subtotal > self::RETENCIONES_DESDE;

        if ($nacional) {
            $totals['iva'] = $client->iva ? $base * ($client->iva / 100) : 0.0;
            if ($retencionesAplican) {
                $totals['rete_ica'] = $client->ica ? $base * ($client->ica / 100) : 0.0;
                $totals['rete_iva'] = $client->reteiva ? $base * ($client->reteiva / 100) : 0.0;
                $totals['rete_fte'] = $client->retefuente ? $base * ($client->retefuente / 100) : 0.0;
            }
            // subtotal + IVA - ReteICA - ReteIVA - Retefuente
            $totals['total'] = $base + $totals['iva'] - $totals['rete_ica'] - $totals['rete_iva'] - $totals['rete_fte'];
        }

        return [
            'items' => $items,
            'totals' => $totals,
            'flete_aplica' => $fleteAplica,
            'retenciones_aplican' => $retencionesAplican,
        ];
    }

    /** Fecha en que vence una proforma emitida en $emision (días hábiles, sin sáb/dom). */
    public function venceEl(Carbon $emision): Carbon
    {
        $fecha = $emision->copy()->startOfDay();
        $restantes = self::VALIDEZ_DIAS_HABILES;
        while ($restantes > 0) {
            $fecha->addDay();
            if (! $fecha->isWeekend()) {
                $restantes--;
            }
        }

        return $fecha;
    }

    /**
     * Estado de vigencia: vigente | por_vencer | vencida, con los días hábiles que quedan.
     *
     * @return array{estado: string, dias_habiles_restantes: int, vence: string}
     */
    public function vigencia(Carbon $emision, ?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $vence = $this->venceEl($emision);

        $restantes = 0;
        if ($hoy->lt($vence)) {
            $d = $hoy->copy();
            while ($d->lt($vence)) {
                $d->addDay();
                if (! $d->isWeekend()) {
                    $restantes++;
                }
            }
        }

        $estado = $hoy->gt($vence) ? 'vencida' : ($restantes <= self::AVISO_DIAS_HABILES ? 'por_vencer' : 'vigente');

        return ['estado' => $estado, 'dias_habiles_restantes' => $restantes, 'vence' => $vence->toDateString()];
    }

    /**
     * Genera el PDF de la proforma.
     *
     * @param  array  $lines   líneas (code, description, quantity, price_usd)
     * @param  array  $params  country, date (Y-m-d), flete, trm
     * @param  string $referencia  consecutivo de la orden (o "MANUAL-...") que se imprime
     */
    public function renderPdf(Client $client, array $lines, array $params, string $referencia, string $numeroFactura): string
    {
        $country = $params['country'];
        $nacional = $this->esNacional($country);
        $calc = $this->calcular($client, $lines, $country, (float) $params['trm'], (float) $params['flete']);

        $data = [
            'logoUrl' => $this->logoBase64(),
            'orden_compra' => $referencia,
            'fecha_trm' => Carbon::parse($params['date'])->locale('es')->isoFormat('dddd DD [de] MMMM [del] YYYY'),
            'trm' => $nacional ? $params['trm'] : null,
            'currency' => $nacional ? 'COP' : 'USD',
            'company' => [
                'name' => 'FINEAROM SAS',
                'nit' => '900.220.672-8',
                'address' => 'CRA 50 134 D 31',
                'city' => 'BOGOTÁ - COLOMBIA',
                'phone' => '57 1 6150035',
                'email' => 'servicio.cliente@finearom.com',
            ],
            'client' => [
                'name' => $client->client_name,
                'nit' => $client->nit,
                'address' => $client->address,
                'city' => $client->city,
                'phone' => $client->phone,
                'email' => $client->email,
            ],
            'invoice' => ['number' => $numeroFactura],
            'items' => $calc['items'],
            'client_rates' => [
                'iva' => $client->iva,
                'ica' => $client->ica,
                'reteiva' => $client->reteiva,
                'retefuente' => $client->retefuente,
            ],
            'totals' => $calc['totals'],
            'cuenta_bancaria' => self::CUENTA_BANCARIA,
            'validez_dias' => self::VALIDEZ_DIAS_HABILES,
            'retenciones_aplican' => $calc['retenciones_aplican'],
        ];

        $pdf = \PDF::loadView('pdf.proforma', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'defaultFont' => 'Arial',
        ]);

        return $pdf->output();
    }

    private function logoBase64(): string
    {
        $logoPath = public_path('images/logo.png');
        if (! file_exists($logoPath)) {
            return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
}

<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ForecastWeeklyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly array $emailData
    ) {}

    public function envelope(): Envelope
    {
        $service = app(EmailTemplateService::class);
        $subject = $service->getRenderedSubject('forecast_weekly', $this->prepareVariables());

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $service  = app(EmailTemplateService::class);
        $rendered = $service->renderTemplate('forecast_weekly', $this->prepareVariables());

        return new Content(
            view: 'emails.template',
            with: $rendered,
        );
    }

    private function prepareVariables(): array
    {
        $data = $this->emailData;

        return [
            'ejecutiva'            => $data['ejecutiva'],
            'primer_nombre'        => explode(' ', trim($data['ejecutiva']))[0],
            'mes'                  => $data['mes'],
            'año'                  => $data['año'],
            'semana'               => $data['semana'],
            'total_pronostico'     => number_format($data['total_pronostico']) . ' kg  /  $' . number_format($data['total_pron_usd'] ?? 0, 2),
            'total_vendido'        => number_format($data['total_vendido']) . ' kg  /  $' . number_format($data['total_vendido_usd'] ?? 0, 2),
            'total_cumplimiento'   => $data['total_cumplimiento'] !== null
                ? $data['total_cumplimiento'] . '%'
                : '—',
            'mensaje_motivacional' => $this->buildMensajeMotivacional($data['total_cumplimiento']),
            'tabla_detalle'        => $this->buildTablaHtml($data['clientes']),
        ];
    }

    private function buildMensajeMotivacional(?float $cumplimiento): string
    {
        if ($cumplimiento === null) return '';

        if ($cumplimiento >= 100) {
            $pct = number_format($cumplimiento, 1);
            return '
<p style="margin:0 0 10px;font-size:14px;color:#374151;line-height:1.6;">
  Quiero felicitarte porque a la fecha has superado el pronóstico del mes en un
  <strong>' . $pct . '%</strong>.
  Este resultado refleja tu compromiso y esfuerzo.
</p>
<p style="margin:0 0 10px;font-size:14px;color:#374151;line-height:1.6;">
  Ahora el foco está en cerrar los pendientes, por favor revisemos con cada cliente las OC para asegurar el cumplimiento total.
</p>
<p style="margin:0 0 16px;font-size:14px;color:#374151;line-height:1.6;">
  Gracias por tu esfuerzo y participación a la venta de este mes.
</p>';
        }

        return '
<p style="margin:0 0 10px;font-size:14px;color:#374151;line-height:1.6;">
  A la fecha presentas un buen avance frente al pronóstico del mes; sin embargo, aún hay oportunidades
  por concretar para alcanzar la meta. Confío en tu gestión y enfoque para lograrlo.
</p>
<p style="margin:0 0 10px;font-size:14px;color:#374151;line-height:1.6;">
  Ahora el foco está en cerrar los pendientes, por favor valida con cada cliente las OC para asegurar el cumplimiento total.
</p>
<p style="margin:0 0 16px;font-size:14px;color:#374151;line-height:1.6;">
  Gracias por tu esfuerzo y participación a la venta de este mes.
</p>';
    }

    private function buildTablaHtml(array $clientes): string
    {
        if (empty($clientes)) return '';

        $html = '';

        foreach ($clientes as $cliente) {
            $html .= '<div style="margin-bottom:20px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">';
            $html .= '<div style="background:#f9fafb;padding:10px 16px;font-weight:700;font-size:13px;color:#111827;border-bottom:1px solid #e5e7eb;">';
            $html .= htmlspecialchars($cliente['nombre']);
            $html .= '</div>';
            // border:none en tabla y celdas para sobreescribir el CSS global del layout
            $html .= '<table style="width:100%;border-collapse:collapse;border:none;margin:0;">';
            $html .= '<thead><tr style="background:#f1f5f9;">';
            $html .= '<th style="padding:8px 16px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Producto</th>';
            $html .= '<th style="padding:8px 16px;text-align:right;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Pron. kg</th>';
            $html .= '<th style="padding:8px 16px;text-align:right;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Pron. USD</th>';
            $html .= '<th style="padding:8px 16px;text-align:right;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Desp. kg</th>';
            $html .= '<th style="padding:8px 16px;text-align:right;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Desp. USD</th>';
            $html .= '<th style="padding:8px 16px;text-align:right;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Pend. despacho</th>';
            $html .= '<th style="padding:8px 16px;text-align:center;font-size:11px;color:#64748b;text-transform:uppercase;border:none;font-weight:600;">Cumplim.</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($cliente['productos'] as $prod) {
                $c   = $prod['cumplimiento'];
                $bg  = $c === null ? '' : ($c >= 80 ? '#dcfce7' : ($c >= 40 ? '#fef9c3' : '#fee2e2'));
                $fc  = $c === null ? '#9ca3af' : ($c >= 80 ? '#166534' : ($c >= 40 ? '#854d0e' : '#991b1b'));
                $txt = $c !== null ? $c . '%' : '—';

                $html .= '<tr style="border-top:1px solid #f1f5f9;">';
                $html .= '<td style="padding:10px 16px;border:none;">';
                $html .= '<div style="font-weight:600;font-size:13px;color:#111827;">' . htmlspecialchars($prod['nombre']) . '</div>';
                $html .= '<div style="font-size:11px;color:#9ca3af;">Cód. ' . htmlspecialchars($prod['codigo']) . '</div>';
                $html .= '</td>';
                $html .= '<td style="padding:10px 16px;text-align:right;font-size:13px;color:#374151;border:none;">' . number_format($prod['pronostico']) . ' kg</td>';
                $html .= '<td style="padding:10px 16px;text-align:right;font-size:12px;color:#64748b;border:none;">$' . number_format($prod['pron_usd'] ?? 0, 2) . '</td>';
                $html .= '<td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:#1e40af;border:none;">' . number_format($prod['vendido']) . ' kg</td>';
                $html .= '<td style="padding:10px 16px;text-align:right;font-size:12px;font-weight:600;color:#059669;border:none;">$' . number_format($prod['vendido_usd'] ?? 0, 2) . '</td>';

                // Pendiente por despachar = pronóstico - vendido (mínimo 0)
                $pendiente = max(0, (int) $prod['pronostico'] - (int) $prod['vendido']);
                $pendColor = $pendiente === 0 ? '#059669' : '#b45309';  // verde si 0, ámbar si queda pendiente
                $html .= '<td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:' . $pendColor . ';border:none;">' . number_format($pendiente) . ' kg</td>';

                $html .= '<td style="padding:10px 16px;text-align:center;border:none;">';
                $html .= '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;background:' . $bg . ';color:' . $fc . ';">' . $txt . '</span>';
                $html .= '</td>';
                $html .= '</tr>';
            }

            // Fila de resumen del cliente
            $totalPron  = array_sum(array_column($cliente['productos'], 'pronostico'));
            $totalVend  = array_sum(array_column($cliente['productos'], 'vendido'));
            $cumplims   = array_filter(array_column($cliente['productos'], 'cumplimiento'), fn($v) => $v !== null);
            $avgCumplim = count($cumplims) > 0 ? round(array_sum($cumplims) / count($cumplims), 1) : null;
            $avgBg      = $avgCumplim === null ? '#f1f5f9' : ($avgCumplim >= 80 ? '#dcfce7' : ($avgCumplim >= 40 ? '#fef9c3' : '#fee2e2'));
            $avgFc      = $avgCumplim === null ? '#64748b' : ($avgCumplim >= 80 ? '#166534' : ($avgCumplim >= 40 ? '#854d0e' : '#991b1b'));

            $totalPronUsdCliente  = array_sum(array_column($cliente['productos'], 'pron_usd'));
            $totalVendUsdCliente  = array_sum(array_column($cliente['productos'], 'vendido_usd'));

            // Total pendiente del cliente = suma de max(0, pron - vend) por producto
            $totalPend = 0;
            foreach ($cliente['productos'] as $p) {
                $totalPend += max(0, (int) $p['pronostico'] - (int) $p['vendido']);
            }
            $totalPendColor = $totalPend === 0 ? '#059669' : '#b45309';

            $html .= '<tr style="border-top:2px solid #e5e7eb;background:#f8fafc;">';
            $html .= '<td style="padding:10px 16px;border:none;font-weight:700;font-size:12px;color:#374151;text-transform:uppercase;letter-spacing:.04em;">Resumen cliente</td>';
            $html .= '<td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:#374151;border:none;">' . number_format($totalPron) . ' kg</td>';
            $html .= '<td style="padding:10px 16px;text-align:right;font-size:12px;font-weight:700;color:#64748b;border:none;">$' . number_format($totalPronUsdCliente, 2) . '</td>';
            $html .= '<td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:#1e40af;border:none;">' . number_format($totalVend) . ' kg</td>';
            $html .= '<td style="padding:10px 16px;text-align:right;font-size:12px;font-weight:700;color:#059669;border:none;">$' . number_format($totalVendUsdCliente, 2) . '</td>';
            $html .= '<td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:' . $totalPendColor . ';border:none;">' . number_format($totalPend) . ' kg</td>';
            $html .= '<td style="padding:10px 16px;text-align:center;border:none;">';
            if ($avgCumplim !== null) {
                $html .= '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;background:' . $avgBg . ';color:' . $avgFc . ';">' . $avgCumplim . '%</span>';
            } else {
                $html .= '<span style="color:#9ca3af;font-size:12px;">—</span>';
            }
            $html .= '</td></tr>';

            $html .= '</tbody></table></div>';
        }

        return $html;
    }
}

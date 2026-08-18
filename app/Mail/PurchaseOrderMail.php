<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use App\Models\ConfigSystem;
use App\Models\BranchOffice;
use App\Services\EmailTemplateService;

class PurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $purchaseOrder;
    public $pdf;
    public $processType;
    public $customMetadata;
    public $templateContent;
    public $userAttachments;
    public $forecastExceedances;

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param $pdf
     * @param string $processType
     * @param array $customMetadata
     * @param array $userAttachments
     */
    public function __construct($purchaseOrder, $pdf, $processType = 'purchase_order', $customMetadata = [], $userAttachments = [], array $forecastExceedances = [])
    {
        $this->purchaseOrder      = $purchaseOrder;
        $this->pdf                = $pdf;
        $this->processType        = $processType;
        $this->customMetadata     = $customMetadata;
        $this->userAttachments    = is_array($userAttachments) ? $userAttachments : [];
        $this->forecastExceedances = $forecastExceedances;

        // Obtener el contenido del template desde ConfigSystem (backward compatibility)
        $config = ConfigSystem::where('key', 'templatePedido')->first();
        $this->templateContent = $config->value ?? '<p>Estimado cliente,</p><p>Espero que se encuentren muy bien.</p><p>Confirmo el recibido de su orden de compra, estamos trabajando en su pedido para cumplir en el menor tiempo posible.</p><p>Cualquier novedad con la disponibilidad y el despacho estaremos informando, brindándole el mejor servicio.</p><p>Que tengan un excelente día.</p><p>Cordialmente,</p> <br> <p><b>Nota: Queremos reiterar nuestro compromiso en brindar siempre el mejor servicio. En línea con nuestra política de envíos, le informamos que los pedidos cuyo valor antes de IVA no alcanza el monto mínimo establecido están sujetos al cobro de flete</b></p>';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // Usar subject_client (puede ser personalizado o default "Orden de Compra - consecutivo")
        $subject = $this->purchaseOrder->subject_client;

        // Headers para seguir el hilo del correo original
        $headers = [];
        $threadId = $this->purchaseOrder->message_id;
        if ($threadId) {
            $headers['In-Reply-To'] = '<' . $threadId . '>';
            $headers['References'] = '<' . $threadId . '>';
        }

        $tagHeader = json_encode([
            'type'     => 'order',
            'purchase' => $this->purchaseOrder->id,
        ]);

        return new Envelope(
            subject: $subject,
            tags: [$tagHeader],
            using: [
                function ($message) use ($headers) {
                    foreach ($headers as $key => $value) {
                        $message->getHeaders()->addTextHeader($key, $value);
                    }
                }
            ]
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(
            text: [
                'X-Process-Type' => $this->processType,
                'X-Metadata' => json_encode($this->customMetadata),
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $service         = new EmailTemplateService();
        $variables       = $this->prepareVariables();
        $templateContent = $service->replaceVariables($this->templateContent, $variables);

        // Inyectar aviso de pronóstico antes de "Nota:" si hay productos que exceden
        $avisoPronostico = $this->buildForecastExceedancesBlock();
        if ($avisoPronostico !== '') {
            // Buscar "Nota:" (case-insensitive) y meter el bloque justo antes
            $pos = stripos($templateContent, 'nota:');
            if ($pos !== false) {
                $templateContent = substr($templateContent, 0, $pos)
                    . $avisoPronostico
                    . substr($templateContent, $pos);
            } else {
                $templateContent .= $avisoPronostico;
            }
        }

        $variables['template_content'] = $templateContent;
        $rendered = $service->renderTemplate('purchase_order', $variables);

        return new Content(
            view: 'emails.template',
            with: $rendered
        );
    }

    private function buildForecastExceedancesBlock(): string
    {
        if (empty($this->forecastExceedances)) return '';

        $th = 'padding:8px 12px;text-align:left;border:1px solid #d1d5db;background:#f9fafb;font-size:13px;color:#374151;font-weight:bold;';
        $td = 'padding:8px 12px;border:1px solid #d1d5db;font-size:13px;color:#374151;';

        $rows = '';
        foreach ($this->forecastExceedances as $row) {
            $rows .= '<tr>'
                . '<td style="' . $td . '">' . e($row['nombre']) . ' <span style="color:#9ca3af;font-size:11px;">(' . e($row['codigo']) . ')</span></td>'
                . '<td style="' . $td . 'text-align:right;">' . number_format($row['cantidad'], 0, ',', '.') . ' kg</td>'
                . '<td style="' . $td . 'text-align:right;font-weight:bold;color:#b45309;">' . number_format($row['excedente'], 0, ',', '.') . ' kg</td>'
                . '</tr>';
        }

        return '<p style="font-family:Arial,sans-serif;font-size:13px;color:#1F2345;margin:16px 0 8px 0;">'
            . 'Los siguientes productos podrían presentar novedades en la fecha de entrega debido a que la cantidad solicitada supera el pronóstico.'
            . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 16px 0;font-family:Arial,sans-serif;">'
            . '<thead><tr>'
            . '<th style="' . $th . '">Referencia</th>'
            . '<th style="' . $th . 'text-align:right;">Cantidad solicitada</th>'
            . '<th style="' . $th . 'text-align:right;">Excede en</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    /**
     * Prepare variables for template rendering
     */
    protected function prepareVariables(): array
    {
        $this->purchaseOrder->loadMissing('client');

        return [
            'client_name' => $this->purchaseOrder->client?->client_name ?? '',
            'client_nit' => $this->purchaseOrder->client?->nit ?? '',
            'subject_client' => $this->purchaseOrder->subject_client,
            'branch_offices' => $this->buildBranchOfficesTable(),
        ];
    }

    /**
     * Build branch offices table HTML for the client
     */
    protected function buildBranchOfficesTable(): string
    {
        $offices = BranchOffice::query()
            ->where('client_id', $this->purchaseOrder->client_id)
            ->orderBy('id')
            ->get(['id', 'name', 'nit', 'delivery_address', 'delivery_city']);

        if ($offices->isEmpty()) {
            return '<p style="font-family:Arial,sans-serif;font-size:13px;color:#1F2345;"><em>No hay sucursales registradas.</em></p>';
        }

        $tableStyle   = 'width:100%;border-collapse:collapse;margin:12px 0;border:1px solid #1F2345;font-family:Arial,sans-serif;font-size:13px;';
        $thStyle      = 'border:1px solid #1F2345;padding:8px 12px;text-align:left;background-color:#f8f9fa;font-weight:bold;color:#1F2345;';
        $tdStyle      = 'border:1px solid #1F2345;padding:8px 12px;text-align:left;color:#1F2345;';
        $h4Style      = 'font-family:Arial,sans-serif;font-size:14px;font-weight:bold;color:#1F2345;margin:12px 0 6px 0;';

        $html  = '<div>';
        $html .= '<h4 style="' . $h4Style . '">Sucursales de entrega</h4>';
        $html .= '<table style="' . $tableStyle . '">';
        $html .=     '<thead><tr>';
        $html .=         '<th style="' . $thStyle . '">NOMBRE</th>';
        $html .=         '<th style="' . $thStyle . '">NIT</th>';
        $html .=         '<th style="' . $thStyle . '">DIRECCIÓN DE ENTREGA</th>';
        $html .=         '<th style="' . $thStyle . '">CIUDAD</th>';
        $html .=     '</tr></thead>';
        $html .=     '<tbody>';

        foreach ($offices as $office) {
            $html .= '<tr>';
            $html .=     '<td style="' . $tdStyle . '"><strong>' . e($office->name) . '</strong></td>';
            $html .=     '<td style="' . $tdStyle . '">' . e($office->nit ?: '-') . '</td>';
            $html .=     '<td style="' . $tdStyle . '">' . e($office->delivery_address) . '</td>';
            $html .=     '<td style="' . $tdStyle . '">' . e($office->delivery_city) . '</td>';
            $html .= '</tr>';
        }

        $html .=     '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        // Email pedido (cliente): solo el PDF generado de la orden, sin adjuntos del usuario
        return [
            Attachment::fromData(fn () => $this->pdf, 'orden-' . $this->purchaseOrder->order_consecutive . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

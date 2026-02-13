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

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param $pdf
     * @param string $processType
     * @param array $customMetadata
     * @param array $userAttachments
     */
    public function __construct($purchaseOrder, $pdf, $processType = 'purchase_order', $customMetadata = [], $userAttachments = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->pdf = $pdf;
        $this->processType = $processType;
        $this->customMetadata = $customMetadata;
        $this->userAttachments = is_array($userAttachments) ? $userAttachments : [];

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
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $templateContent = $service->replaceVariables($this->templateContent, $variables);
        $variables['template_content'] = $templateContent;
        $rendered = $service->renderTemplate('purchase_order', $variables);

        return new Content(
            view: 'emails.template',
            with: $rendered
        );
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
            return '<p><em>No hay sucursales registradas.</em></p>';
        }

        $html = '<div>
            <h4>Sucursales de entrega</h4>
            <table>
                <thead>
                    <tr>
                        <th>NOMBRE</th>
                        <th>NIT</th>
                        <th>DIRECCIÓN DE ENTREGA</th>
                        <th>CIUDAD</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($offices as $office) {
            $html .= '<tr>
                <td><strong>' . e($office->name) . '</strong></td>
                <td>' . e($office->nit ?: '-') . '</td>
                <td>' . e($office->delivery_address) . '</td>
                <td>' . e($office->delivery_city) . '</td>
            </tr>';
        }

        $html .= '</tbody>
            </table>
        </div>';

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

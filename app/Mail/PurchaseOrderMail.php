<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use App\Models\ConfigSystem;

class PurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $processType;
    public $metadata;

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param $pdf
     * @param string $processType
     * @param array $metadata
     */
    public function __construct($purchaseOrder, $pdf, $processType = 'purchase_order', $metadata = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->pdf = $pdf;
        $this->processType = $processType;
        $this->metadata = $metadata;

        $config = ConfigSystem::where('key', 'templatePedido')->first();
        $this->templateContent = $config->value ?? '<p>Estimado cliente,</p><p>Espero que se encuentren muy bien.</p><p>Confirmo el recibido de su orden de compra, estamos trabajando en su pedido para cumplir en el menor tiempo posible.</p><p>Cualquier novedad con la disponibilidad y el despacho estaremos informando, brindándole el mejor servicio.</p><p>Que tengan un excelente día.</p><p>Cordialmente,</p> <br> <p><b>Nota: Queremos reiterar nuestro compromiso en brindar siempre el mejor servicio. En línea con nuestra política de envíos, le informamos que los pedidos cuyo valor antes de IVA no alcanza el monto mínimo establecido están sujetos al cobro de flete</b></p>';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Re: ' . $this->purchaseOrder->subject_client,
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
                'X-Metadata' => json_encode($this->metadata),
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase_order',
            with: ['templateContent' => $this->templateContent]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, 'order.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

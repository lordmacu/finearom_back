<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use App\Models\ConfigSystem;

class ProformaMail extends Mailable
{
    use Queueable, SerializesModels;

    public $purchaseOrder;
    public $pdf;
    public $templateContent;

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param $pdf
     */
    public function __construct($purchaseOrder, $pdf)
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->pdf = $pdf;

        $config = ConfigSystem::where('key', 'templateProforma')->first();
        $this->templateContent = $config->value ?? '<p>Estimado cliente,</p><p>Espero que se encuentren muy bien.</p><p>Adjuntamos la proforma correspondiente a su solicitud de pedido para su revisión y aprobación.</p><p>Cualquier inquietud adicional, estaremos atentos para brindarle el mejor servicio.</p><p>Que tengan un excelente día.</p><p>Cordialmente,</p>';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Proforma - Orden de Compra: ' . $this->purchaseOrder->order_consecutive,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase_order', // Reusing the same layout as purchase order for consistency
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
            Attachment::fromData(fn () => $this->pdf, 'proforma-' . $this->purchaseOrder->order_consecutive . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

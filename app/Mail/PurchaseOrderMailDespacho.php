<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class PurchaseOrderMailDespacho extends Mailable
{
    use Queueable, SerializesModels;

    public $processType;
    public $metadata;

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param $pdfAttachment
     * @param string $processType
     * @param array $metadata
     */
    public function __construct($purchaseOrder, $pdfAttachment, $processType = 'purchase_order_despacho', $metadata = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->pdfAttachment = $pdfAttachment;
        $this->processType = $processType;
        $this->metadata = $metadata;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->purchaseOrder->is_new_win == 1 ? 'NEW WIN - ' : '') .
                     'Pedido - ' .
                     $this->purchaseOrder->client->client_name . ' - ' .
                     $this->purchaseOrder->client->nit . ' - ' .
                     $this->purchaseOrder->order_consecutive,
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
            view: 'emails.purchase_order_email',
            with: ['purchaseOrder' => $this->purchaseOrder]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        if (!$this->pdfAttachment) {
            return [];
        }

        $fullPath = Storage::disk('public')->path($this->pdfAttachment);

        return [
            Attachment::fromPath($fullPath)
                ->as('adjunto.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

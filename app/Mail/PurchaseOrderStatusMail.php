<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;
    public ?string $invoicePdfPath;
    public ?string $statusCommentHtml;

    /**
     * Create a new message instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder, ?string $invoicePdfPath = null, ?string $statusCommentHtml = null)
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->invoicePdfPath = $invoicePdfPath;
        $this->statusCommentHtml = $statusCommentHtml;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = 'CONFIRMACIÓN DE DESPACHO ' . 
                   strtoupper($this->purchaseOrder->client->client_name) . ' ' . 
                   $this->purchaseOrder->client->nit . ' OC ' . 
                   $this->purchaseOrder->order_consecutive;

        // Add email threading headers if message_id exists
        $headers = [];
        if ($this->purchaseOrder->message_id) {
            $headers['In-Reply-To'] = '<' . $this->purchaseOrder->message_id . '>';
            $headers['References'] = '<' . $this->purchaseOrder->message_id . '>';
        }

        return new Envelope(
            subject: $subject,
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
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase_order_status_update',
            with: [
                'purchaseOrder' => $this->purchaseOrder,
                'statusCommentHtml' => $this->statusCommentHtml,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        if ($this->invoicePdfPath && file_exists(storage_path('app/public/' . $this->invoicePdfPath))) {
            $attachments[] = Attachment::fromStorageDisk('public', $this->invoicePdfPath)
                ->as(basename($this->invoicePdfPath));
        }

        return $attachments;
    }
}

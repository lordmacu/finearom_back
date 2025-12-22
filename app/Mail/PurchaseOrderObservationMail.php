<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderObservationMail extends Mailable
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;
    public string $observationText;
    public ?string $internalObservation;

    /**
     * Create a new message instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder, string $observationText, ?string $internalObservation = null)
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->observationText = $observationText;
        $this->internalObservation = $internalObservation;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $prefix = $this->purchaseOrder->is_new_win ? 'Re: NEW WIN - ' : 'Re: ';
        $subject = $prefix . 'Pedido - ' . 
                   $this->purchaseOrder->client->client_name . ' - ' . 
                   $this->purchaseOrder->client->nit . ' - ' . 
                   $this->purchaseOrder->order_consecutive;

        // Add email threading headers if message_despacho_id exists (fallback message_id)
        $headers = [];
        $threadId = $this->purchaseOrder->message_despacho_id ?: $this->purchaseOrder->message_id;
        if ($threadId) {
            $headers['In-Reply-To'] = '<' . $threadId . '>';
            $headers['References'] = '<' . $threadId . '>';
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
            view: 'emails.purchase_order_observation',
            with: [
                'purchaseOrder' => $this->purchaseOrder,
                'observationText' => $this->observationText,
                'internalObservation' => $this->internalObservation,
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
        return [];
    }
}

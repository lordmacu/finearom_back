<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Services\EmailTemplateService;

class PurchaseOrderStatusChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;
    public $processType;
    public $customMetadata;

    /**
     * Create a new message instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder, $processType = 'status_change', $customMetadata = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->processType = $processType;
        $this->customMetadata = $customMetadata;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = 'Re: ' . $this->purchaseOrder->subject_client;

        // Headers para seguir el hilo del correo original
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
        $rendered = $service->renderTemplate('purchase_order_status_changed', $variables);

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
        $statusTranslations = [
            'pending' => 'Pendiente',
            'processing' => 'En Proceso',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
            'parcial_status' => 'Parcial',
        ];

        return [
            'order_consecutive' => $this->purchaseOrder->order_consecutive,
            'client_name' => $this->purchaseOrder->client->client_name,
            'client_nit' => $this->purchaseOrder->client->nit,
            'status_label' => $statusTranslations[$this->purchaseOrder->status] ?? 'Estado Desconocido',
            'order_creation_date' => $this->purchaseOrder->order_creation_date,
            'required_delivery_date' => $this->purchaseOrder->required_delivery_date,
            'delivery_address' => $this->purchaseOrder->delivery_address ?? 'No especificada',
        ];
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

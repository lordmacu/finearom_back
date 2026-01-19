<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Services\EmailTemplateService;

class PurchaseOrderObservationMail extends Mailable
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;
    public string $observationText;
    public ?string $internalObservation;
    public $processType;
    public $customMetadata;

    /**
     * Create a new message instance.
     */
    public function __construct(PurchaseOrder $purchaseOrder, string $observationText, ?string $internalObservation = null, $processType = 'purchase_order_observation', $customMetadata = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->observationText = $observationText;
        $this->internalObservation = $internalObservation;
        $this->processType = $processType;
        $this->customMetadata = $customMetadata;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $subject = $service->getRenderedSubject('purchase_order_observation', $variables);

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
        $rendered = $service->renderTemplate('purchase_order_observation', $variables);

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
        // Combinar observación del cliente con observación interna si existe
        $observations = $this->observationText;
        if ($this->internalObservation) {
            $observations .= '<div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #6c757d;">
                <p><strong>Nota Interna:</strong></p>
                <div>' . $this->internalObservation . '</div>
            </div>';
        }

        return [
            'order_consecutive' => $this->purchaseOrder->order_consecutive,
            'client_name' => $this->purchaseOrder->client->client_name,
            'client_nit' => $this->purchaseOrder->client->nit,
            'observations' => $observations,
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

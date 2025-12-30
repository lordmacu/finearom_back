<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use App\Services\EmailTemplateService;

class ProformaMail extends Mailable
{
    use Queueable, SerializesModels;

    public $purchaseOrder;
    public $pdf;

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
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $service = new EmailTemplateService();
        $variables = [
            'order_consecutive' => $this->purchaseOrder->order_consecutive,
        ];
        $subject = $service->getRenderedSubject('proforma', $variables);

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $service = new EmailTemplateService();
        $variables = [
            'order_consecutive' => $this->purchaseOrder->order_consecutive,
        ];
        $rendered = $service->renderTemplate('proforma', $variables);

        return new Content(
            view: 'emails.template',
            with: $rendered
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

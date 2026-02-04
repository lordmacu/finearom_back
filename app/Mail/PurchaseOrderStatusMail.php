<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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
    public ?string $subjectBase;
    public bool $isReply;

    /**
     * Create a new message instance.
     */
    public function __construct(
        PurchaseOrder $purchaseOrder,
        ?string $invoicePdfPath = null,
        ?string $statusCommentHtml = null,
        ?string $subjectBase = null,
        bool $isReply = false
    )
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->invoicePdfPath = $invoicePdfPath;
        $this->statusCommentHtml = $statusCommentHtml;
        $this->subjectBase = $subjectBase;
        $this->isReply = $isReply;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $baseSubject = $this->subjectBase ?? (
            'CONFIRMACIÓN DE DESPACHO ' .
            strtoupper($this->purchaseOrder->client->client_name) . ' ' .
            $this->purchaseOrder->client->nit . ' OC ' .
            $this->purchaseOrder->order_consecutive
        );

        $subject = $this->isReply ? 'Re: ' . $baseSubject : $baseSubject;

        return new Envelope(
            subject: $subject
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $service = new EmailTemplateService();
        $variables = [
            'status_comment' => $this->statusCommentHtml ?? '',
            'sender_name' => $this->purchaseOrder->sender_name ?? 'EQUIPO FINEAROM',
        ];
        $rendered = $service->renderTemplate('purchase_order_status_update', $variables);

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
        $attachments = [];

        if ($this->invoicePdfPath && file_exists(storage_path('app/public/' . $this->invoicePdfPath))) {
            $attachments[] = Attachment::fromStorageDisk('public', $this->invoicePdfPath)
                ->as(basename($this->invoicePdfPath));
        }

        return $attachments;
    }
}

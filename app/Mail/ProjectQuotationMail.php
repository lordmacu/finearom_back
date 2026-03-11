<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectQuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        private readonly string $pdfContent,
        public readonly int $version,
    ) {}

    public function envelope(): Envelope
    {
        $clientName = $this->project->client?->client_name ?? $this->project->nombre_prospecto ?? 'Cliente';
        return new Envelope(
            subject: "Cotización Finearom — {$this->project->nombre} (v{$this->version})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project_quotation',
            with: [
                'project' => $this->project,
                'version' => $this->version,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => $this->pdfContent,
                "cotizacion_{$this->project->id}_v{$this->version}.pdf"
            )->withMime('application/pdf'),
        ];
    }
}

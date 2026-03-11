<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectUrgencyAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project
    ) {}

    public function envelope(): Envelope
    {
        $fecha = $this->project->fecha_requerida?->format('d/m/Y') ?? '—';
        return new Envelope(
            subject: "⚠️ Proyecto urgente: {$this->project->nombre} — vence {$fecha}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project_urgency_alert',
            with: ['project' => $this->project],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

<?php

namespace App\Mail;

use App\Models\Project;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectUrgencyAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    private array $rendered;

    public function __construct(
        public readonly Project $project
    ) {
        $service = new EmailTemplateService();
        $fecha   = $project->fecha_requerida?->format('d/m/Y') ?? '—';

        $projectTable = '
<table>
    <tbody>
        <tr><td><strong>Proyecto</strong></td><td>' . e($project->nombre) . '</td></tr>
        <tr><td><strong>Cliente</strong></td><td>' . e($project->client?->client_name ?? $project->nombre_prospecto ?? '—') . '</td></tr>
        <tr><td><strong>Tipo</strong></td><td>' . e($project->tipo) . '</td></tr>
        <tr><td><strong>Fecha requerida</strong></td><td>' . $fecha . '</td></tr>
        <tr><td><strong>Estado interno</strong></td><td>' . e($project->estado_interno ?? '—') . '</td></tr>
        <tr><td><strong>Ejecutivo</strong></td><td>' . e($project->ejecutivo ?? '—') . '</td></tr>
    </tbody>
</table>';

        $this->rendered = $service->renderTemplate('project_urgency_alert', [
            'project_name'    => $project->nombre,
            'fecha_requerida' => $fecha,
            'project_table'   => $projectTable,
            'project_url'     => config('app.frontend_url', 'https://ordenes.finearom.co') . '/projects/' . $project->id,
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.template',
            with: $this->rendered,
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

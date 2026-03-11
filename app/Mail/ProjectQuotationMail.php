<?php

namespace App\Mail;

use App\Models\Project;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectQuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    private array $rendered;

    public function __construct(
        public readonly Project $project,
        private readonly string $pdfContent,
        public readonly int $version,
    ) {
        $service    = new EmailTemplateService();
        $clientName = $project->client?->client_name ?? $project->nombre_prospecto ?? 'cliente';

        $projectTable = '
<table>
    <tbody>
        <tr><td><strong>Proyecto</strong></td><td>' . e($project->nombre) . '</td></tr>
        <tr><td><strong>Tipo</strong></td><td>' . e($project->tipo) . '</td></tr>'
        . ($project->tipo_producto ? '<tr><td><strong>Tipo de producto</strong></td><td>' . e($project->tipo_producto) . '</td></tr>' : '')
        . ($project->ejecutivo ? '<tr><td><strong>Ejecutivo</strong></td><td>' . e($project->ejecutivo) . '</td></tr>' : '') . '
        <tr><td><strong>Versión cotización</strong></td><td>' . $version . '</td></tr>
    </tbody>
</table>';

        $this->rendered = $service->renderTemplate('project_quotation', [
            'client_name'   => $clientName,
            'project_name'  => $project->nombre,
            'version'       => (string) $version,
            'project_table' => $projectTable,
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
        return [
            Attachment::fromData(
                fn () => $this->pdfContent,
                "cotizacion_{$this->project->id}_v{$this->version}.pdf"
            )->withMime('application/pdf'),
        ];
    }
}

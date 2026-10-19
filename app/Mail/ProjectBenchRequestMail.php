<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\ProjectEvaluation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Pide la compra del bench cuando el proyecto lo marca como NO disponible: lleva la foto
 * y la descripción del bench. Destinatarios en config('custom.bench_request_*').
 */
class ProjectBenchRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectEvaluation $evaluation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Compra de bench — ' . $this->project->nombre);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.project_bench_request',
            with: [
                'asunto'     => 'Compra de bench — ' . $this->project->nombre,
                'project'    => $this->project,
                'evaluation' => $this->evaluation,
                'url'        => rtrim(config('app.frontend_url', 'https://ordenes.finearom.co'), '/') . '/projects/' . $this->project->id,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $path = $this->evaluation->bench_image;
        if (!$path || !Storage::disk('local')->exists($path)) {
            return [];
        }

        return [Attachment::fromStorageDisk('local', $path)->as('bench.' . pathinfo($path, PATHINFO_EXTENSION))];
    }
}

<?php

namespace App\Mail;

use App\Models\Client;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ClientWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $client;
    public $emailData;
    public $includeAttachments;

    /**
     * Create a new message instance.
     */
    public function __construct(Client $client, array $emailData = [], bool $includeAttachments = false)
    {
        $this->client = $client;
        $this->emailData = $emailData;
        $this->includeAttachments = $includeAttachments;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $subject = $service->getRenderedSubject('client_welcome', $variables);

        return new Envelope(
            subject: $subject,
            from: config('mail.from.address', 'monica.castano@finearom.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $rendered = $service->renderTemplate('client_welcome', $variables);

        return new Content(
            view: 'emails.template_centered',
            with: $rendered
        );
    }

    /**
     * Prepara las variables para el template
     */
    protected function prepareVariables(): array
    {
        // Preparar links con mailto y tel
        $executiveEmail = $this->emailData['executive_email'] ?? null;
        $executivePhone = $this->emailData['executive_phone'] ?? null;

        return [
            'client_name' => $this->client->client_name,
            'executive_name' => $this->emailData['executive_name'] ?? 'Equipo Comercial',
            'executive_phone' => $executivePhone ? '<a href="tel:' . $executivePhone . '">' . $executivePhone . '</a>' : '',
            'executive_email' => $executiveEmail ? '<a href="mailto:' . $executiveEmail . '">' . $executiveEmail . '</a>' : '',
            // base_url ya no es necesario, se inyecta automáticamente
        ];
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        if (!$this->includeAttachments) {
            return [];
        }

        $attachments = [];

        $filesToAttach = [
            'rut_file' => 'RUT',
            'camara_comercio_file' => 'Camara_Comercio',
            'cedula_representante_file' => 'Cedula_Representante',
        ];

        if ($this->client->requires_study) {
            $filesToAttach['declaracion_renta_file'] = 'Declaracion_Renta';
            $filesToAttach['estados_financieros_file'] = 'Estados_Financieros';
        }

        foreach ($filesToAttach as $fileField => $fileName) {
            $filePath = $this->client->{$fileField};

            if ($filePath && Storage::disk('public')->exists($filePath)) {
                try {
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $cleanClientName = $this->sanitizeFileName($this->client->client_name);

                    $attachments[] = Attachment::fromStorageDisk('public', $filePath)
                        ->as($fileName . '_' . $cleanClientName . '.' . $extension);
                } catch (\Exception $e) {
                    Log::warning("No se pudo adjuntar archivo {$fileField} para cliente {$this->client->client_name}: " . $e->getMessage());
                }
            }
        }

        return $attachments;
    }

    private function sanitizeFileName($name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);
        $clean = preg_replace('/\s+/', '_', trim($clean));

        return substr($clean, 0, 50);
    }
}

<?php

namespace App\Mail;

use App\Models\Client;
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
        return new Envelope(
            subject: '¡Bienvenido a FINEAROM! - ' . $this->client->client_name,
            from: config('mail.from.address', 'monica.castano@finearom.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.client_welcome',
            with: [
                'client' => $this->client,
                'executiveName' => $this->emailData['executive_name'] ?? null,
                'executiveEmail' => $this->emailData['executive_email'] ?? null,
                'executivePhone' => $this->emailData['executive_phone'] ?? null,
                'welcomeDate' => $this->emailData['welcome_date'] ?? now()->format('d/m/Y'),
                'includeAttachments' => $this->includeAttachments,
            ]
        );
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
            'declaracion_renta_file' => 'Declaracion_Renta',
            'estados_financieros_file' => 'Estados_Financieros',
        ];

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

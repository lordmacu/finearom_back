<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Correo del hilo interno de un proyecto. Un solo Mailable para todas las
 * acciones: el template ya viene renderizado y el asunto/hilo los resuelve
 * ProjectMailService.
 */
class ProjectThreadMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly array $rendered,
        private readonly string $threadSubject,
        private readonly ?string $inReplyTo,
        private readonly string $processType,
        // No llamarlo $metadata: Mailable ya declara `protected $metadata` y redeclararlo es error fatal
        private readonly array $trackingMetadata = [],
        // [['path' => ruta en disk local, 'name' => nombre visible], ...]
        // No llamarlo $attachments: Mailable ya declara esa propiedad (mismo choque que $metadata)
        private readonly array $mailAttachments = [],
        // Message-ID propio al ABRIR el hilo: el transporte SMTP sobreescribe
        // SentMessage::getMessageId() con el id de cola del servidor, así que el
        // id del hilo no puede salir de ahí — hay que fijarlo nosotros
        private readonly ?string $threadMessageId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->threadSubject);
    }

    public function headers(): Headers
    {
        // LogSentMessage consume X-Process-Type / X-Metadata para el log de correos
        $text = [
            'X-Process-Type' => $this->processType,
            'X-Metadata'     => json_encode($this->trackingMetadata),
        ];

        if ($this->inReplyTo) {
            $text['In-Reply-To'] = '<' . $this->inReplyTo . '>';
        }

        return new Headers(
            messageId: $this->threadMessageId,
            references: $this->inReplyTo ? [$this->inReplyTo] : [],
            text: $text,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.template',
            with: array_merge($this->rendered, ['subject' => $this->threadSubject]),
        );
    }

    /** @return Attachment[] */
    public function attachments(): array
    {
        return array_map(
            fn ($file) => Attachment::fromStorageDisk('local', $file['path'])->as($file['name']),
            $this->mailAttachments
        );
    }
}

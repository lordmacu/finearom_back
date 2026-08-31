<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $body,
        public array $attachmentPaths = [],
        public ?int $logId = null,
    ) {
    }

    public function build()
    {
        $service = new EmailTemplateService();

        // Preparar tracking pixel si hay logId
        $trackingPixel = '';
        if ($this->logId) {
            $trackingPixel = '<img src="' . url('/api/email-campaigns/track-open/' . $this->logId) . '" width="1" height="1" style="display:none;" alt="" />';
        }

        $variables = [
            'body' => $this->body,
            'tracking_pixel' => $trackingPixel,
        ];

        $rendered = $service->renderTemplate('campaign', $variables);

        $email = $this->subject($this->mailSubject)
            ->view('emails.template', $rendered);

        Log::info('📎 [MAIL] build() — paths recibidos', [
            'log_id' => $this->logId,
            'paths'  => $this->attachmentPaths,
        ]);

        $attachedCount = 0;
        $skippedCount  = 0;
        foreach ($this->attachmentPaths as $filePath) {
            $absolute = storage_path('app/' . $filePath);
            $exists   = file_exists($absolute);
            $size     = $exists ? filesize($absolute) : null;

            if ($exists) {
                $email->attach($absolute);
                $attachedCount++;
                Log::info('📎 [MAIL] adjunto OK', [
                    'log_id' => $this->logId,
                    'file'   => $filePath,
                    'bytes'  => $size,
                ]);
            } else {
                $skippedCount++;
                Log::error('📎 [MAIL] adjunto FALTANTE — file_exists=false', [
                    'log_id'   => $this->logId,
                    'file'     => $filePath,
                    'abs_path' => $absolute,
                ]);
            }
        }

        Log::info('📎 [MAIL] build() — resumen adjuntos', [
            'log_id'   => $this->logId,
            'attached' => $attachedCount,
            'skipped'  => $skippedCount,
            'mailable_attachments_count' => count($this->attachments ?? []),
        ]);

        $email->withSymfonyMessage(function ($message) {
            $message->getHeaders()->addTextHeader('X-Process-Type', 'email_campaign');
            $message->getHeaders()->addTextHeader('X-Metadata', json_encode([
                'log_id' => $this->logId,
                'subject' => $this->mailSubject
            ]));
        });

        return $email;
    }
}

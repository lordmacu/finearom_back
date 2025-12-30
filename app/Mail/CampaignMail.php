<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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

        if (! empty($this->attachmentPaths)) {
            foreach ($this->attachmentPaths as $filePath) {
                $absolute = storage_path('app/' . $filePath);
                if (file_exists($absolute)) {
                    $email->attach($absolute);
                }
            }
        }

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

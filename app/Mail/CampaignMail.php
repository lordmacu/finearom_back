<?php

namespace App\Mail;

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
        $email = $this->subject($this->mailSubject)
            ->view('emails.campaign', [
                'body' => $this->body,
                'logId' => $this->logId,
            ]);

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


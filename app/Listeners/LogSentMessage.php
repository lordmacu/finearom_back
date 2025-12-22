<?php

namespace App\Listeners;

use App\Services\EmailTrackingService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\TextPart;

class LogSentMessage
{
    protected $trackingService;

    /**
     * Create the event listener.
     */
    public function __construct(EmailTrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    /**
     * Handle the event.
     */
    public function handle(MessageSending $event): void
    {
        // Get the Symfony Message instance
        $message = $event->message;

        if (!$message instanceof Email) {
            return;
        }

        // Extract recipients
        $recipients = [];
        foreach ($message->getTo() as $address) {
            $recipients[] = $address->getAddress();
        }
        $recipientString = implode(', ', $recipients);

        // Extract sender
        $from = $message->getFrom();
        $senderString = !empty($from) ? $from[0]->getAddress() : config('mail.from.address');

        // Extract custom headers for context
        $processType = 'system_automatic';
        $metadata = null;
        
        $headers = $message->getHeaders();
        if ($headers->has('X-Process-Type')) {
            $processType = $headers->get('X-Process-Type')->getBody();
            $headers->remove('X-Process-Type'); // Clean up
        }
        
        if ($headers->has('X-Metadata')) {
            $metaJson = $headers->get('X-Metadata')->getBody();
            $metadata = json_decode($metaJson, true);
            $headers->remove('X-Metadata'); // Clean up
        }

        // Log the email
        $log = $this->trackingService->createLog([
            'sender_email' => $senderString,
            'recipient_email' => $recipientString,
            'subject' => $message->getSubject(),
            'content' => 'Content logging suppressed',
            'process_type' => $processType,
            'metadata' => $metadata,
            'status' => 'sent', // Initially assume sent success if it reaches here
        ]);

        // Inject Pixel into HTML body
        $htmlBody = $message->getHtmlBody();
        if ($htmlBody) {
             // If it's a resource (stream), read it.
            if (is_resource($htmlBody)) {
                rewind($htmlBody);
                $htmlBody = stream_get_contents($htmlBody);
            }

            $newHtml = $this->trackingService->injectPixel($htmlBody, $log->uuid);
            $message->html($newHtml);
        }
    }
}

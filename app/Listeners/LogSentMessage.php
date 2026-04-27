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

        // Extract recipients (TO, CC, BCC)
        $extractAddresses = function ($addresses) {
            $out = [];
            foreach ($addresses ?? [] as $address) {
                $out[] = $address->getAddress();
            }
            return $out;
        };
        $toEmails  = $extractAddresses($message->getTo());
        $ccEmails  = $extractAddresses($message->getCc());
        $bccEmails = $extractAddresses($message->getBcc());
        $recipientString = implode(', ', $toEmails);

        // Extract sender
        $from = $message->getFrom();
        $senderString = !empty($from) ? $from[0]->getAddress() : config('mail.from.address');

        // Extract custom headers for context
        $processType = 'system_automatic';
        $metadata = [];

        $headers = $message->getHeaders();
        if ($headers->has('X-Process-Type')) {
            $processType = $headers->get('X-Process-Type')->getBody();
            $headers->remove('X-Process-Type'); // Clean up
        }

        if ($headers->has('X-Metadata')) {
            $metaJson = $headers->get('X-Metadata')->getBody();
            $decoded = json_decode($metaJson, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
            $headers->remove('X-Metadata'); // Clean up
        }

        // Adjuntar siempre los destinatarios completos en metadata.
        // Si X-Metadata ya trae cc_emails (caso status_change), no pisar.
        $metadata['to_emails'] = $toEmails;
        if (!array_key_exists('cc_emails', $metadata)) {
            $metadata['cc_emails'] = $ccEmails;
        }
        if (!empty($bccEmails)) {
            $metadata['bcc_emails'] = $bccEmails;
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

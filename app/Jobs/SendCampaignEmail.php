<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\EmailCampaignLog;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $campaignId,
        public int $logId,
    ) {
    }

    public function handle(): void
    {
        $log = EmailCampaignLog::with(['campaign', 'client'])->find($this->logId);
        if (! $log) {
            return;
        }

        $campaign = $log->campaign;
        $client = $log->client;

        $log->status = 'sending';
        $log->save();

        try {
            $emailField = $log->email_field_used;
            $emailValue = $client?->{$emailField};

            if (empty($log->email_sent_to) && empty($emailValue) && $log->email_field_used !== 'custom') {
                throw new Exception('No se encontró email en el campo especificado');
            }

            $emails = ! empty($log->email_sent_to)
                ? array_filter(array_map('trim', explode(',', $log->email_sent_to)))
                : array_filter(array_map('trim', explode(',', (string) $emailValue)));

            if (empty($emails)) {
                throw new Exception('No se encontraron emails para enviar');
            }

            Mail::to($emails)->send(new CampaignMail(
                $campaign->subject,
                (string) $campaign->body,
                $campaign->attachments ?? [],
                $this->logId
            ));

            $log->status = 'sent';
            $log->error_message = null;
            $log->sent_at = now();
            $log->save();

            $campaign->increment('sent_count');
            $this->checkAndUpdateCampaignStatus($campaign);
        } catch (Exception $e) {
            $log->status = 'failed';
            $log->error_message = $e->getMessage();
            $log->save();

            $campaign->increment('failed_count');
            $this->checkAndUpdateCampaignStatus($campaign);

            throw $e;
        }
    }

    protected function checkAndUpdateCampaignStatus($campaign): void
    {
        $campaign->refresh();

        $totalProcessed = (int) $campaign->sent_count + (int) $campaign->failed_count;
        if ((int) $campaign->total_recipients > 0 && $totalProcessed >= (int) $campaign->total_recipients) {
            $campaign->status = 'sent';
            $campaign->sent_at = now();
            $campaign->save();
        }
    }
}


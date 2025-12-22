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

class SendCampaignEmailCustom implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $campaignId,
        public int $logId,
        public string $customSubject,
        public string $customBody,
        public array $attachments = [],
    ) {
    }

    public function handle(): void
    {
        $log = EmailCampaignLog::with(['campaign', 'client'])->find($this->logId);
        if (! $log) {
            return;
        }

        $campaign = $log->campaign;

        $log->status = 'sending';
        $log->save();

        try {
            $emails = array_filter(array_map('trim', explode(',', (string) $log->email_sent_to)));
            if (empty($emails)) {
                throw new Exception('No se encontraron emails para enviar');
            }

            Mail::to($emails)->send(new CampaignMail(
                $this->customSubject,
                $this->customBody,
                $this->attachments,
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


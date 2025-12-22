<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailDispatch;
use App\Models\EmailDispatchQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DispatchEmailJobs extends Command
{
    protected $signature = 'emails:dispatch {--force : Force execution ignoring lock}';
    protected $description = 'Dispatch email jobs for pending email notifications';

    public function handle(): int
    {
        $lockKey = 'email_dispatch_lock';
        $lockDuration = 300; // 5 minutos

        $lock = Cache::lock($lockKey, $lockDuration);

        if (! $lock->get()) {
            if ($this->option('force')) {
                $this->warn('Forcing execution, ignoring existing lock...');
                Cache::forget($lockKey);
            } else {
                $this->error('Another instance is running. Use --force to override.');
                return 1;
            }
        }

        try {
            $this->dispatchPendingEmails();
        } finally {
            $lock->release();
        }

        return 0;
    }

    private function dispatchPendingEmails(): void
    {
        $processedCount = 0;

        EmailDispatchQueue::where('send_status', 'pending')
            ->chunkById(100, function ($emails) use (&$processedCount) {
                DB::transaction(function () use ($emails, &$processedCount) {
                    foreach ($emails as $email) {
                        $affected = EmailDispatchQueue::where('id', $email->id)
                            ->where('send_status', 'pending')
                            ->update([
                                'send_status' => 'queued',
                                'queued_at' => now(),
                            ]);

                        if ($affected > 0) {
                            ProcessEmailDispatch::dispatch($email);
                            $processedCount++;
                        }
                    }
                });
            });

        $this->info("Email dispatch jobs queued successfully. Processed: {$processedCount}");
    }
}


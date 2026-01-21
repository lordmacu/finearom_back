<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailDispatch;
use App\Models\EmailDispatchQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchEmailJobs extends Command
{
    protected $signature = 'emails:dispatch {--force : Force execution ignoring lock}';
    protected $description = 'Dispatch email jobs for pending email notifications';

    public function handle(): int
    {
        Log::info('===== DispatchEmailJobs INICIADO =====');
        
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
        $pendingCount = EmailDispatchQueue::where('send_status', 'pending')->count();
        
        Log::info('DispatchEmailJobs - Emails pendientes encontrados', [
            'pending_count' => $pendingCount,
        ]);
        
        $this->info("Emails pendientes: {$pendingCount}");
        
        $processedCount = 0;

        EmailDispatchQueue::where('send_status', 'pending')
            ->chunkById(100, function ($emails) use (&$processedCount) {
                Log::info('DispatchEmailJobs - Procesando chunk', [
                    'chunk_size' => $emails->count(),
                ]);
                
                DB::transaction(function () use ($emails, &$processedCount) {
                    foreach ($emails as $email) {
                        Log::info('DispatchEmailJobs - Procesando email individual', [
                            'email_id' => $email->id,
                            'email_type' => $email->email_type,
                            'client_nit' => $email->client_nit,
                            'send_status' => $email->send_status,
                        ]);
                        
                        $affected = EmailDispatchQueue::where('id', $email->id)
                            ->where('send_status', 'pending')
                            ->update([
                                'send_status' => 'queued',
                                'queued_at' => now(),
                            ]);

                        if ($affected > 0) {
                            ProcessEmailDispatch::dispatch($email);
                            $processedCount++;
                            
                            Log::info('DispatchEmailJobs - Job despachado', [
                                'email_id' => $email->id,
                                'processed_count' => $processedCount,
                            ]);
                        } else {
                            Log::warning('DispatchEmailJobs - Email no actualizado (posible concurrencia)', [
                                'email_id' => $email->id,
                            ]);
                        }
                    }
                });
            });

        Log::info('===== DispatchEmailJobs FINALIZADO =====', [
            'processed_count' => $processedCount,
        ]);
        
        $this->info("Email dispatch jobs queued successfully. Processed: {$processedCount}");
    }
}


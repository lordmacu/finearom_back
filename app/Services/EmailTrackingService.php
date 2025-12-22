<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Str;

class EmailTrackingService
{
    /**
     * Create a new tracking log entry
     */
    public function createLog(array $data): EmailLog
    {
        return EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'sender_email' => $data['sender_email'] ?? config('mail.from.address'),
            'recipient_email' => $data['recipient_email'],
            'subject' => $data['subject'] ?? 'No Subject',
            'content' => $data['content'] ?? null,
            'process_type' => $data['process_type'] ?? 'system',
            'metadata' => $data['metadata'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'sent_at' => now(),
        ]);
    }

    /**
     * Update log status
     */
    public function updateStatus(EmailLog $log, string $status, ?string $error = null): void
    {
        $log->update([
            'status' => $status,
            'error_message' => $error,
        ]);
    }

    /**
     * Register an open event
     */
    public function registerOpen(string $uuid, string $ip, ?string $userAgent): ?EmailLog
    {
        $log = EmailLog::where('uuid', $uuid)->first();

        if ($log) {
            $log->increment('open_count');
            
            // Only update details if this is the first open or overwrite logic matches
            if (!$log->opened_at) {
                $log->update([
                    'opened_at' => now(),
                    'ip_address' => $ip,
                    'user_agent' => $userAgent
                ]);
            }
        }

        return $log;
    }

    /**
     * Inject tracking pixel into HTML content
     */
    public function injectPixel(string $htmlContent, string $uuid): string
    {
        $appUrl = config('app.url');
        // Ensure NO trailing slash in config, but handle gracefully
        $baseUrl = rtrim($appUrl, '/');
        $pixelUrl = "{$baseUrl}/api/tracking/{$uuid}/pixel.png";
        
        $pixelTag = "<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" style=\"display:none;\" alt=\"\" />";
        
        // Try to insert before closing body tag
        if (strpos($htmlContent, '</body>') !== false) {
            return str_replace('</body>', $pixelTag . '</body>', $htmlContent);
        }
        
        // Append if no body tag (fragment)
        return $htmlContent . $pixelTag;
    }
}

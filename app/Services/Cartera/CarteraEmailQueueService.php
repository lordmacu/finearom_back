<?php

namespace App\Services\Cartera;

use App\Models\EmailDispatchQueue;
use Illuminate\Support\Facades\DB;

class CarteraEmailQueueService
{
    /**
     * @param array<int,array<string,mixed>> $groups
     */
    public function enqueue(string $dueDate, string $typeQueue, array $groups): int
    {
        $count = 0;

        DB::transaction(function () use ($dueDate, $typeQueue, $groups, &$count) {
            foreach ($groups as $group) {
                $nit = (string) ($group['nit'] ?? '');
                if ($nit === '') {
                    continue;
                }

                $blockEmails = $this->toEmailString($group['dispatch_confirmation_email'] ?? []);
                $balanceEmails = $this->toEmailString($group['emails'] ?? []);

                EmailDispatchQueue::query()->updateOrCreate(
                    [
                        'client_nit' => $nit,
                        'due_date' => $dueDate,
                        'email_type' => $typeQueue,
                    ],
                    [
                        'order_block_notification_emails' => $blockEmails,
                        'outstanding_balance_notification_emails' => $balanceEmails,
                        'send_status' => 'pending',
                        'retry_count' => 0,
                        'error_message' => null,
                        'email_sent_date' => null,
                    ]
                );

                $count++;
            }
        });

        return $count;
    }

    /**
     * @param array<int,string>|string|null $value
     */
    private function toEmailString(array|string|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }

        $clean = [];
        foreach ($value as $email) {
            $e = strtolower(trim((string) $email));
            if ($e === '' || ! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $clean[$e] = $e;
        }

        return implode(',', array_values($clean));
    }
}


<?php

namespace App\Queries\Cartera;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CarteraEstadoQuery
{
    private const DEFAULT_CARTERA_EMAILS = [
        'coordinadora.comercial@finearom.com',
        'monica.castano@finearom.com',
        'facturacion@finearom.com',
        'katherine.moreno@finearom.com',
        'maribel.sanchez@finearom.com',
        'analista.operaciones@finearom.com',
        'cartera@finearom.com',
    ];

    /**
     * Retorna la cartera agrupada por NIT con emails y estados (bloqueo/cartera).
     *
     * @return array<int,array<string,mixed>>
     */
    public function loadByDate(string $fechaCartera): array
    {
        $rows = DB::table('cartera as car')
            ->leftJoin('clients as c', 'car.nit', '=', 'c.nit')
            ->where('car.fecha_cartera', '=', $fechaCartera)
            ->select(
                'car.nit',
                'car.documento',
                'car.fecha',
                'car.vence',
                'car.dias',
                'car.saldo_contable',
                'car.saldo_vencido',
                'car.catera_type',
                'c.client_name',
                'c.executive_email',
                'c.email',
                'c.portfolio_contact_email',
            )
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $statuses = $this->loadStatuses($fechaCartera);

        return $rows
            ->groupBy('nit')
            ->map(function (Collection $items, string $nit) use ($fechaCartera, $statuses) {
                $first = $items->first();

                $sortedCuentas = $items->sortBy('dias')->values()->map(function ($item) {
                    return [
                        'documento' => (string) $item->documento,
                        'fecha_cartera' => $item->fecha_cartera ?? null,
                        'fecha' => $item->fecha ? Carbon::parse($item->fecha)->toDateString() : null,
                        'vence' => $item->vence ? Carbon::parse($item->vence)->toDateString() : null,
                        'dias' => (int) ($item->dias ?? 0),
                        'catera_type' => $item->catera_type,
                        'saldo_vencido' => round($this->parseNumber($item->saldo_vencido), 2),
                        'saldo_contable' => round($this->parseNumber($item->saldo_contable), 2),
                    ];
                })->all();

                $totalVencidos = (float) $items->filter(fn($i) => (int) ($i->dias ?? 0) < 0)->sum(fn($i) => $this->parseNumber($i->saldo_contable));
                $totalPorVencer = (float) $items->filter(fn($i) => (int) ($i->dias ?? 0) > 0)->sum(fn($i) => $this->parseNumber($i->saldo_contable));

                $executiveEmail = $this->normalizeEmail($first->executive_email ?? null);
                $clientEmails = $this->parseEmailList($first->email ?? '');
                $portfolioEmails = $this->parseEmailList($first->portfolio_contact_email ?? '');

                $emailsBlock = array_merge(self::DEFAULT_CARTERA_EMAILS, $executiveEmail ? [$executiveEmail] : []);
                $emailsBlock = $this->cleanEmailArray($emailsBlock);

                $emailsBalance = array_merge(
                    $clientEmails,
                    $executiveEmail ? [$executiveEmail] : [],
                    ['monica.castano@finearom.com', 'coordinadora.comercial@finearom.com'],
                    $portfolioEmails,
                );
                $emailsBalance = $this->cleanEmailArray($emailsBalance);

                $statusBlock = $statuses[$nit]['order_block'] ?? ['send_status' => 'no_sent', 'error_message' => null];
                $statusBalance = $statuses[$nit]['balance_notification'] ?? ['send_status' => 'no_sent', 'error_message' => null];

                // Si no hay nombre de cliente, evitamos ofrecer envío.
                if (empty($first->client_name)) {
                    $emailsBlock = [];
                    $emailsBalance = [];
                }

                return [
                    'client_name' => $first->client_name,
                    'nit' => $nit,
                    'cuentas' => $sortedCuentas,
                    'total_vencidos' => round($totalVencidos, 2),
                    'total_por_vencer' => round($totalPorVencer, 2),
                    'dispatch_confirmation_email' => $emailsBlock,
                    'emails' => $emailsBalance,
                    'status_sender_block' => $statusBlock['send_status'] ?? 'no_sent',
                    'status_sender_balance' => $statusBalance['send_status'] ?? 'no_sent',
                    'error_message_block' => $statusBlock['error_message'] ?? null,
                    'error_message_balance' => $statusBalance['error_message'] ?? null,
                    'isChecked' => true,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,array<string,array{send_status:string|null,error_message:string|null}>>
     */
    private function loadStatuses(string $dueDate): array
    {
        $rows = DB::table('email_dispatch_queues')
            ->whereDate('due_date', '=', $dueDate)
            ->whereIn('email_type', ['order_block', 'balance_notification'])
            ->orderByDesc('id')
            ->get(['client_nit', 'email_type', 'send_status', 'error_message']);

        $out = [];
        foreach ($rows as $row) {
            $nit = (string) $row->client_nit;
            $type = (string) $row->email_type;
            if (! isset($out[$nit][$type])) {
                $out[$nit][$type] = [
                    'send_status' => $row->send_status,
                    'error_message' => $row->error_message,
                ];
            }
        }
        return $out;
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $value = strtolower(trim((string) $email));
        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $value;
    }

    /**
     * @return array<int,string>
     */
    private function parseEmailList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param array<int,string|null> $emails
     * @return array<int,string>
     */
    private function cleanEmailArray(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            $value = strtolower(trim((string) $email));
            if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[$value] = $value;
        }
        return array_values($out);
    }

    private function parseNumber(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return 0.0;
        }

        if (str_contains($stringValue, ',') && str_contains($stringValue, '.')) {
            $stringValue = str_replace('.', '', $stringValue);
            $stringValue = str_replace(',', '.', $stringValue);
        } elseif (str_contains($stringValue, ',')) {
            $stringValue = str_replace(',', '.', $stringValue);
        }

        $stringValue = preg_replace('/[^0-9.\-]/', '', $stringValue) ?? $stringValue;
        return (float) $stringValue;
    }
}


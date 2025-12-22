<?php

namespace App\Services\Trm;

use App\Models\TrmDaily;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrmDailyWarmup
{
    public function __construct(private readonly TrmSoapClient $soapClient)
    {
    }

    public function warmForAnalyze(
        Carbon $from,
        Carbon $to,
        string $type,
        ?string $status,
        ?int $clientId = null
    ): void {
        $query = DB::table('partials')
            ->join('purchase_orders', 'partials.order_id', '=', 'purchase_orders.id')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id')
            ->leftJoin('trm_daily', 'partials.dispatch_date', '=', 'trm_daily.date')
            ->where('partials.type', $type)
            ->whereNull('partials.deleted_at')
            ->whereBetween('partials.dispatch_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('trm_daily.date');

        if ($clientId) {
            $query->where('clients.id', $clientId);
        }

        if ($status) {
            if ($status === 'completed') {
                $query->whereIn('purchase_orders.status', ['parcial_status', 'completed']);
            } elseif ($status === 'pending') {
                $query->where('purchase_orders.status', 'pending');
            } elseif ($status === 'processing') {
                $query->where('purchase_orders.status', 'processing');
            } elseif ($status === 'parcial_status') {
                if ($type === 'temporal') {
                    $query->where('purchase_orders.status', 'parcial_status');
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        $missingDates = $query
            ->distinct()
            ->orderBy('partials.dispatch_date')
            ->pluck('partials.dispatch_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->values();

        foreach ($missingDates as $date) {
            try {
                $result = $this->soapClient->fetch($date);
                TrmDaily::updateOrCreate(
                    ['date' => $date],
                    [
                        'value' => $result['value'],
                        'source' => 'soap',
                        'metadata' => $result,
                        'is_weekend' => false,
                        'is_holiday' => false,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('TRM warmup failed for date ' . $date . ': ' . $e->getMessage());
            }
        }
    }
}


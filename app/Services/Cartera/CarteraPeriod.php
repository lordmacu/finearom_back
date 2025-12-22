<?php

namespace App\Services\Cartera;

use Carbon\Carbon;

class CarteraPeriod
{
    /**
     * @param array<string,mixed> $params
     * @return array{0:Carbon,1:Carbon}
     */
    public function resolve(array $params): array
    {
        $timezone = 'America/Bogota';
        $now = Carbon::now($timezone);

        $isMonthlyRaw = $params['is_monthly'] ?? null;
        $isMonthly = $isMonthlyRaw === null
            ? true
            : (filter_var($isMonthlyRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);

        $weekNumber = isset($params['week_number']) ? (int) $params['week_number'] : null;

        if ($isMonthly) {
            return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        }

        $startOfMonth = $now->copy()->startOfMonth();

        return match ($weekNumber) {
            1 => [$startOfMonth->copy(), $startOfMonth->copy()->addDays(6)],
            2 => [$startOfMonth->copy()->addDays(7), $startOfMonth->copy()->addDays(13)],
            3 => [$startOfMonth->copy()->addDays(14), $startOfMonth->copy()->addDays(20)],
            4 => [$startOfMonth->copy()->addDays(21), $startOfMonth->copy()->endOfMonth()],
            default => [$startOfMonth->copy(), $now->copy()->endOfMonth()],
        };
    }
}


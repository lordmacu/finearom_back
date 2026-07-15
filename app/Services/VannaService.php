<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VannaService
{
    public function retrieve(string $question, ?int $k = null): ?array
    {
        if (!config('custom.vanna_enabled')) {
            return null;
        }

        $k = $k ?? (int) config('custom.vanna_k', 8);
        $base = rtrim((string) config('custom.vanna_url'), '/');
        $timeout = max(0.1, ((int) config('custom.vanna_timeout_ms', 600)) / 1000);

        try {
            $resp = Http::timeout($timeout)
                ->post("$base/retrieve", ['question' => $question, 'k' => $k]);

            if (!$resp->successful()) {
                Log::warning('[Vanna] retrieve no exitoso: ' . $resp->status());
                return null;
            }

            $data = $resp->json();
            return [
                'examples'      => $data['examples'] ?? [],
                'ddl'           => $data['ddl'] ?? [],
                'documentation' => $data['documentation'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('[Vanna] retrieve falló (fallback a estático): ' . $e->getMessage());
            return null;
        }
    }
}

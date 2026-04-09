<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SiigoBridgeUrl
{
    /**
     * Get the current Siigo Bridge URL.
     * Priority:
     *   1. Latest URL registered in siigo_bridge_tunnels table (pushed by the bridge)
     *   2. Fallback to SIIGO_PROXY_URL env var
     */
    public static function get(): string
    {
        // Try the database first (latest tunnel URL pushed by the bridge)
        try {
            $record = DB::table('siigo_bridge_tunnels')
                ->orderByDesc('updated_at')
                ->first();

            if ($record && !empty($record->tunnel_url)) {
                return rtrim($record->tunnel_url, '/') . '/api';
            }
        } catch (\Throwable $e) {
            // Table might not exist yet (before migration)
        }

        // Fallback to env config
        return rtrim(config('custom.siigo_proxy_url', ''), '/');
    }

    /**
     * Get URL for a specific user/customer
     */
    public static function getForUser(int $userId): ?string
    {
        try {
            $record = DB::table('siigo_bridge_tunnels')
                ->where('user_id', $userId)
                ->first();

            if ($record && !empty($record->tunnel_url)) {
                return rtrim($record->tunnel_url, '/') . '/api';
            }
        } catch (\Throwable $e) {
            //
        }
        return null;
    }
}

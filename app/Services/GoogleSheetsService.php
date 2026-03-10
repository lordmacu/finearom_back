<?php

namespace App\Services;

use App\Models\UserGoogleToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';
    private const DRIVE_SCOPE  = 'https://www.googleapis.com/auth/drive.file';
    private const SHEETS_URL   = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const DRIVE_URL    = 'https://www.googleapis.com/drive/v3/files';

    // ─── Verificación ─────────────────────────────────────────────────────────

    public function hasSheetsAccess(int $userId): bool
    {
        $token = UserGoogleToken::where('user_id', $userId)->first();
        if (!$token) {
            return false;
        }
        $scopes = $token->scopes ?? [];
        return in_array(self::SHEETS_SCOPE, $scopes);
    }

    /**
     * Retorna el primer usuario con acceso a Sheets, o null si ninguno tiene.
     */
    public function getAnyUserWithSheetsAccess(): ?int
    {
        $tokens = UserGoogleToken::whereNotNull('scopes')->get();
        foreach ($tokens as $token) {
            $scopes = $token->scopes ?? [];
            if (in_array(self::SHEETS_SCOPE, $scopes)) {
                return $token->user_id;
            }
        }
        return null;
    }

    // ─── Sheet mensual ────────────────────────────────────────────────────────

    /**
     * Obtiene o crea el spreadsheet mensual de órdenes completadas.
     * El ID se guarda en cache (Laravel cache) para no buscarlo cada vez.
     * Nombre: "Finearom — Órdenes Completadas — Marzo 2026"
     */
    public function getOrCreateMonthlySheet(int $userId): ?string
    {
        $monthKey   = now()->format('Y-m');
        $cacheKey   = "google_sheets_monthly_{$monthKey}";
        $sheetId    = cache($cacheKey);

        if ($sheetId) {
            return $sheetId;
        }

        try {
            $accessToken = $this->getValidToken($userId);
            $title = 'Finearom — Órdenes Completadas — ' . ucfirst(now()->translatedFormat('F Y'));

            // Crear spreadsheet con encabezados
            $response = Http::withToken($accessToken)
                ->post(self::SHEETS_URL, [
                    'properties' => ['title' => $title, 'locale' => 'es_CO'],
                    'sheets'     => [[
                        'properties' => ['title' => 'Órdenes'],
                        'data'       => [[
                            'startRow'    => 0,
                            'startColumn' => 0,
                            'rowData'     => [[
                                'values' => array_map(
                                    fn($v) => ['userEnteredValue' => ['stringValue' => $v],
                                               'userEnteredFormat' => ['textFormat' => ['bold' => true], 'backgroundColor' => ['red' => 0.18, 'green' => 0.44, 'blue' => 0.71]]],
                                    ['Fecha', 'OC #', 'Cliente', 'NIT', 'Total USD', 'Total COP', 'TRM', 'Dirección entrega', 'Ejecutivo', 'Productos']
                                ),
                            ]],
                        ]],
                    ]],
                ]);

            if ($response->failed()) {
                Log::warning("GoogleSheets: fallo al crear sheet mensual para user {$userId}: " . $response->body());
                return null;
            }

            $sheetId = $response->json('spreadsheetId');

            // Compartir con el dominio finearom.com (todos los empleados pueden ver)
            $this->shareWithDomain($userId, $sheetId, 'finearom.com');

            // También "cualquier persona con el link puede ver"
            $this->makePublicReadable($userId, $sheetId);

            // Guardar en cache hasta fin de mes
            $ttl = now()->endOfMonth()->diffInSeconds(now());
            cache([$cacheKey => $sheetId], $ttl);

            return $sheetId;
        } catch (\Throwable $e) {
            Log::warning("GoogleSheets: excepción al crear sheet mensual: " . $e->getMessage());
            return null;
        }
    }

    // ─── Append fila ──────────────────────────────────────────────────────────

    /**
     * Agrega una fila con los datos de una orden completada.
     * Nunca lanza excepción.
     */
    public function appendOrderRow(int $userId, \App\Models\PurchaseOrder $order): void
    {
        try {
            $sheetId = $this->getOrCreateMonthlySheet($userId);
            if (!$sheetId) {
                return;
            }

            $accessToken = $this->getValidToken($userId);

            // Calcular totales
            $totalUsd = 0;
            $totalCop = 0;
            $products = [];
            foreach ($order->products as $product) {
                $qty   = $product->pivot->quantity ?? 0;
                $price = $product->pivot->price ?? 0;
                $totalUsd += $qty * $price;
                $products[] = $product->product_name . ' x' . $qty;
            }
            $trm = (float) ($order->trm ?? 1);
            $totalCop = $totalUsd * $trm;

            $row = [
                $order->dispatch_date?->format('d/m/Y') ?? now()->format('d/m/Y'),
                $order->order_consecutive ?? '',
                $order->client->client_name ?? '',
                $order->client->nit ?? '',
                number_format($totalUsd, 2, '.', ','),
                number_format($totalCop, 0, '.', ','),
                number_format($trm, 2, '.', ','),
                $order->delivery_address ?? '',
                $order->contact ?? '',
                implode(' | ', $products),
            ];

            $response = Http::withToken($accessToken)
                ->post(self::SHEETS_URL . "/{$sheetId}/values/Órdenes!A:J:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS", [
                    'values' => [$row],
                ]);

            if ($response->failed()) {
                Log::warning("GoogleSheets: fallo al agregar fila para OC {$order->order_consecutive}: " . $response->body());
            }
        } catch (\Throwable $e) {
            Log::warning("GoogleSheets: excepción al agregar fila: " . $e->getMessage());
        }
    }

    // ─── Permisos ─────────────────────────────────────────────────────────────

    /**
     * Comparte el spreadsheet con todo el dominio (solo lectura).
     */
    private function shareWithDomain(int $userId, string $fileId, string $domain): void
    {
        try {
            $accessToken = $this->getValidToken($userId);
            Http::withToken($accessToken)
                ->post(self::DRIVE_URL . "/{$fileId}/permissions", [
                    'type'   => 'domain',
                    'role'   => 'reader',
                    'domain' => $domain,
                ]);
        } catch (\Throwable $e) {
            Log::warning("GoogleSheets: fallo al compartir con dominio {$domain}: " . $e->getMessage());
        }
    }

    /**
     * Hace el spreadsheet accesible para cualquier persona con el link.
     */
    private function makePublicReadable(int $userId, string $fileId): void
    {
        try {
            $accessToken = $this->getValidToken($userId);
            Http::withToken($accessToken)
                ->post(self::DRIVE_URL . "/{$fileId}/permissions", [
                    'type' => 'anyone',
                    'role' => 'reader',
                ]);
        } catch (\Throwable $e) {
            Log::warning("GoogleSheets: fallo al hacer público el sheet: " . $e->getMessage());
        }
    }

    // ─── Token ────────────────────────────────────────────────────────────────

    private function getValidToken(int $userId): string
    {
        $token = UserGoogleToken::where('user_id', $userId)->firstOrFail();

        if ($token->expires_at->isPast()) {
            $response = Http::post(self::TOKEN_URL, [
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => decrypt($token->refresh_token),
                'grant_type'    => 'refresh_token',
            ]);

            if ($response->failed()) {
                $token->delete();
                throw new \RuntimeException('La sesión de Google expiró. Por favor reconecta tu cuenta.');
            }

            $tokens = $response->json();
            $token->update([
                'access_token' => encrypt($tokens['access_token']),
                'expires_at'   => now()->addSeconds($tokens['expires_in'] - 60),
            ]);

            return $tokens['access_token'];
        }

        return decrypt($token->access_token);
    }
}

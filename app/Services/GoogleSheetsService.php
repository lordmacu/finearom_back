<?php

namespace App\Services;

use App\Models\PurchaseOrder;
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

    // Columnas hoja Órdenes
    private const HEADERS_ORDERS = [
        'Fecha despacho', 'OC #', 'Estado', 'Cliente', 'NIT', 'Sucursal',
        'Ciudad entrega', 'Total USD', 'Total COP', 'TRM',
        'Factura #', 'Guía #', 'Transportadora',
        'Es muestra', 'Tiene new win', 'Proyecto',
        'Ejecutivo', 'Dirección entrega',
    ];

    // Columnas hoja Ítems
    private const HEADERS_ITEMS = [
        'Fecha despacho', 'OC #', 'Estado', 'Cliente', 'NIT',
        'Producto', 'Cantidad', 'Precio USD', 'Subtotal USD', 'Subtotal COP', 'TRM',
        'Es muestra', 'New win',
    ];

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
     * Obtiene o crea el spreadsheet mensual.
     * Contiene dos hojas: "Órdenes" e "Ítems".
     */
    public function getOrCreateMonthlySheet(int $userId): ?string
    {
        $monthKey = now()->format('Y-m');
        $cacheKey = "google_sheets_monthly_{$monthKey}";
        $sheetId  = cache($cacheKey);

        if ($sheetId) {
            return $sheetId;
        }

        try {
            $accessToken = $this->getValidToken($userId);
            $title = 'Finearom — Órdenes — ' . ucfirst(now()->translatedFormat('F Y'));

            $response = Http::withToken($accessToken)
                ->post(self::SHEETS_URL, [
                    'properties' => ['title' => $title, 'locale' => 'es_CO'],
                    'sheets'     => [
                        [
                            'properties' => ['title' => 'Órdenes', 'index' => 0],
                            'data'       => [[
                                'startRow'    => 0,
                                'startColumn' => 0,
                                'rowData'     => [['values' => $this->headerRow(self::HEADERS_ORDERS)]],
                            ]],
                        ],
                        [
                            'properties' => ['title' => 'Ítems', 'index' => 1],
                            'data'       => [[
                                'startRow'    => 0,
                                'startColumn' => 0,
                                'rowData'     => [['values' => $this->headerRow(self::HEADERS_ITEMS)]],
                            ]],
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning("GoogleSheets: fallo al crear sheet mensual para user {$userId}: " . $response->body());
                return null;
            }

            $sheetId = $response->json('spreadsheetId');

            $this->shareWithDomain($userId, $sheetId, 'finearom.com');
            $this->makePublicReadable($userId, $sheetId);

            $ttl = now()->endOfMonth()->diffInSeconds(now());
            cache([$cacheKey => $sheetId], $ttl);

            return $sheetId;
        } catch (\Throwable $e) {
            Log::warning("GoogleSheets: excepción al crear sheet mensual: " . $e->getMessage());
            return null;
        }
    }

    // ─── Append fila de orden ─────────────────────────────────────────────────

    /**
     * Agrega una fila en "Órdenes" y filas en "Ítems" para cada producto.
     * Nunca lanza excepción — fallo silencioso.
     */
    public function appendOrderRow(int $userId, PurchaseOrder $order): void
    {
        try {
            $sheetId = $this->getOrCreateMonthlySheet($userId);
            if (!$sheetId) {
                return;
            }

            $accessToken = $this->getValidToken($userId);

            // Totales desde los productos del pivot (precio acordado en la OC)
            $totalUsd = 0;
            foreach ($order->products as $product) {
                $qty   = $product->pivot->quantity ?? 0;
                $price = $product->pivot->price    ?? 0;
                $totalUsd += $qty * $price;
            }
            $trm      = (float) ($order->trm ?? 1);
            $totalCop = $totalUsd * $trm;

            $dispatchDate = $order->dispatch_date?->format('d/m/Y') ?? now()->format('d/m/Y');
            $estado       = $order->status === 'completed' ? 'Completo' : 'Parcial';

            // ── Hoja Órdenes ──
            $orderRow = [
                $dispatchDate,
                $order->order_consecutive ?? '',
                $estado,
                $order->client->client_name ?? '',
                $order->client->nit ?? '',
                $order->branchOffice->branch_name ?? '',
                $order->delivery_city ?? '',
                number_format($totalUsd, 2, '.', ','),
                number_format($totalCop, 0, '.', ','),
                number_format($trm, 2, '.', ','),
                $order->invoice_number ?? '',
                $order->tracking_number ?? '',
                '',  // transportadora (está en partials, no en la orden)
                $order->is_muestra ? 'Sí' : 'No',
                $order->is_new_win  ? 'Sí' : 'No',
                $order->project?->name ?? '',
                $order->contact ?? '',
                $order->delivery_address ?? '',
            ];

            $this->appendRow($accessToken, $sheetId, 'Órdenes', $orderRow);

            // ── Hoja Ítems (una fila por producto) ──
            foreach ($order->products as $product) {
                $qty      = $product->pivot->quantity ?? 0;
                $price    = $product->pivot->price    ?? 0;
                $subtotalUsd = $qty * $price;
                $subtotalCop = $subtotalUsd * $trm;

                $itemRow = [
                    $dispatchDate,
                    $order->order_consecutive ?? '',
                    $estado,
                    $order->client->client_name ?? '',
                    $order->client->nit ?? '',
                    $product->product_name ?? '',
                    $qty,
                    number_format($price, 2, '.', ','),
                    number_format($subtotalUsd, 2, '.', ','),
                    number_format($subtotalCop, 0, '.', ','),
                    number_format($trm, 2, '.', ','),
                    ($product->pivot->muestra ?? false) ? 'Sí' : 'No',
                    ($product->pivot->new_win  ?? false) ? 'Sí' : 'No',
                ];

                $this->appendRow($accessToken, $sheetId, 'Ítems', $itemRow);
            }
        } catch (\Throwable $e) {
            Log::warning("GoogleSheets: excepción al agregar fila OC {$order->order_consecutive}: " . $e->getMessage());
        }
    }

    // ─── Helpers internos ─────────────────────────────────────────────────────

    private function appendRow(string $accessToken, string $sheetId, string $sheetName, array $row): void
    {
        $encodedSheet = rawurlencode($sheetName);
        $response = Http::withToken($accessToken)
            ->post(self::SHEETS_URL . "/{$sheetId}/values/{$encodedSheet}!A:Z:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS", [
                'values' => [$row],
            ]);

        if ($response->failed()) {
            Log::warning("GoogleSheets: fallo al agregar fila en '{$sheetName}': " . $response->body());
        }
    }

    private function headerRow(array $headers): array
    {
        return array_map(fn ($v) => [
            'userEnteredValue'  => ['stringValue' => $v],
            'userEnteredFormat' => [
                'textFormat'       => ['bold' => true],
                'backgroundColor'  => ['red' => 0.18, 'green' => 0.44, 'blue' => 0.71],
            ],
        ], $headers);
    }

    // ─── Permisos ─────────────────────────────────────────────────────────────

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

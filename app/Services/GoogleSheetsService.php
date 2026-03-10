<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\UserGoogleToken;
use Illuminate\Support\Facades\DB;
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

    // ─── Carpeta Drive ────────────────────────────────────────────────────────

    /**
     * Obtiene o crea la carpeta "Finearom" en Drive.
     * El ID se guarda en cache permanente (no expira).
     */
    private function getOrCreateReportsFolder(int $userId): ?string
    {
        $cacheKey = 'google_drive_finearom_folder_id';
        $folderId = cache($cacheKey);

        if ($folderId) {
            return $folderId;
        }

        try {
            $accessToken = $this->getValidToken($userId);

            // Buscar si ya existe la carpeta
            $search = Http::withToken($accessToken)
                ->get(self::DRIVE_URL, [
                    'q'      => "name='Finearom' and mimeType='application/vnd.google-apps.folder' and trashed=false",
                    'fields' => 'files(id)',
                ]);

            if ($search->successful() && !empty($search->json('files'))) {
                $folderId = $search->json('files.0.id');
            } else {
                $create = Http::withToken($accessToken)
                    ->post(self::DRIVE_URL, [
                        'name'     => 'Finearom',
                        'mimeType' => 'application/vnd.google-apps.folder',
                    ]);

                if ($create->failed()) {
                    Log::warning('GoogleSheets: no se pudo crear carpeta Finearom en Drive: ' . $create->body());
                    return null;
                }

                $folderId = $create->json('id');
                $this->shareWithDomain($userId, $folderId, 'finearom.com');
            }

            cache([$cacheKey => $folderId]); // sin TTL = permanente
            return $folderId;
        } catch (\Throwable $e) {
            Log::warning('GoogleSheets: excepción al obtener carpeta Finearom: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mueve un archivo a la carpeta Finearom en Drive.
     */
    private function moveToReportsFolder(int $userId, string $fileId): void
    {
        $folderId = $this->getOrCreateReportsFolder($userId);
        if (!$folderId) {
            return;
        }

        try {
            $accessToken = $this->getValidToken($userId);
            Http::withToken($accessToken)
                ->patch(self::DRIVE_URL . "/{$fileId}?addParents={$folderId}&removeParents=root&fields=id,parents");
        } catch (\Throwable $e) {
            Log::warning('GoogleSheets: no se pudo mover sheet a carpeta Finearom: ' . $e->getMessage());
        }
    }

    /**
     * Elimina archivos con el mismo nombre dentro de la carpeta Finearom.
     * Evita que queden sheets duplicados del mismo mes.
     */
    private function deleteExistingSheetByTitle(int $userId, string $title, string $folderId): void
    {
        try {
            $accessToken = $this->getValidToken($userId);
            $q = "name='{$title}' and '{$folderId}' in parents and mimeType='application/vnd.google-apps.spreadsheet' and trashed=false";

            $search = Http::withToken($accessToken)
                ->get(self::DRIVE_URL, ['q' => $q, 'fields' => 'files(id)']);

            foreach ($search->json('files') ?? [] as $file) {
                Http::withToken($accessToken)->delete(self::DRIVE_URL . '/' . $file['id']);
                Log::info("GoogleSheets: eliminado sheet anterior '{$title}' (id: {$file['id']})");
            }
        } catch (\Throwable $e) {
            Log::warning('GoogleSheets: no se pudo eliminar sheet existente: ' . $e->getMessage());
        }
    }

    // ─── Sheet mensual ────────────────────────────────────────────────────────

    /**
     * Obtiene o crea el spreadsheet mensual dentro de la carpeta "Finearom".
     * Si ya existe uno con el mismo nombre, lo elimina y crea uno nuevo.
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
            $title    = 'Finearom — Órdenes — ' . ucfirst(now()->translatedFormat('F Y'));
            $folderId = $this->getOrCreateReportsFolder($userId);

            // Eliminar sheet anterior con el mismo nombre si existe
            if ($folderId) {
                $this->deleteExistingSheetByTitle($userId, $title, $folderId);
            }

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
                Log::warning("GoogleSheets: fallo al crear sheet mensual: " . $response->body());
                return null;
            }

            $sheetId = $response->json('spreadsheetId');

            $this->moveToReportsFolder($userId, $sheetId);
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
     * Idempotente: si este estado ya fue exportado para esta orden, no duplica.
     * Nunca lanza excepción — fallo silencioso.
     */
    public function appendOrderRow(int $userId, PurchaseOrder $order): void
    {
        // Clave única: estado + fecha de despacho (un mismo estado/día no se duplica)
        $exportKey = $order->status . '_' . ($order->dispatch_date?->toDateString() ?? now()->toDateString());
        $exports   = $order->sheets_exports ?? [];

        if (in_array($exportKey, $exports)) {
            Log::info("GoogleSheets: OC {$order->order_consecutive} ya exportada con clave '{$exportKey}', omitiendo.");
            return;
        }

        try {
            $sheetId = $this->getOrCreateMonthlySheet($userId);
            if (!$sheetId) {
                return;
            }

            $accessToken = $this->getValidToken($userId);

            // ── Fuente de verdad: partials reales (misma lógica que el dashboard) ──
            $partials = \DB::table('partials')
                ->join('purchase_order_product as pop', 'partials.product_order_id', '=', 'pop.id')
                ->join('products as p', 'pop.product_id', '=', 'p.id')
                ->where('partials.type', 'real')
                ->where('pop.purchase_order_id', $order->id)
                ->whereNull('partials.deleted_at')
                ->select(
                    'partials.quantity',
                    'partials.dispatch_date',
                    'partials.invoice_number',
                    'partials.tracking_number',
                    'partials.transporter',
                    'partials.trm as partial_trm',
                    'pop.muestra',
                    'pop.new_win',
                    'pop.price as pivot_price',
                    'p.price as product_price',
                    'p.product_name',
                )
                ->get();

            $dispatchDate = $order->dispatch_date?->format('d/m/Y') ?? now()->format('d/m/Y');
            $estado       = $order->status === 'completed' ? 'Completo' : 'Parcial';
            $orderTrm     = (float) ($order->trm ?? 4000);

            $totalUsd     = 0.0;
            $totalCop     = 0.0;
            // Transportadora e invoice del primer partial (o de la orden si no hay parciales)
            $transporter  = '';
            $invoiceNum   = $order->invoice_number ?? '';
            $trackingNum  = $order->tracking_number ?? '';

            // ── Hoja Ítems (una fila por partial real) ──
            foreach ($partials as $partial) {
                $isSample = (int) $partial->muestra === 1;

                // Precio efectivo: pivot_price > 0, si no, precio base del producto
                $effectivePrice = $isSample ? 0.0
                    : (float) (($partial->pivot_price > 0) ? $partial->pivot_price : ($partial->product_price ?? 0));

                // TRM: del partial → del order → default 4000
                $trm = ((float)($partial->partial_trm ?? 0) > 0)
                    ? (float) $partial->partial_trm
                    : ($orderTrm > 0 ? $orderTrm : 4000.0);

                $qty         = (int) $partial->quantity;
                $subtotalUsd = $effectivePrice * $qty;
                $subtotalCop = $subtotalUsd * $trm;

                $totalUsd += $subtotalUsd;
                $totalCop += $subtotalCop;

                if (!$transporter && $partial->transporter) {
                    $transporter = $partial->transporter;
                }
                if (!$invoiceNum && $partial->invoice_number) {
                    $invoiceNum = $partial->invoice_number;
                }
                if (!$trackingNum && $partial->tracking_number) {
                    $trackingNum = $partial->tracking_number;
                }

                $itemRow = [
                    $partial->dispatch_date ? \Carbon\Carbon::parse($partial->dispatch_date)->format('d/m/Y') : $dispatchDate,
                    $order->order_consecutive ?? '',
                    $estado,
                    $order->client->client_name ?? '',
                    $order->client->nit ?? '',
                    $partial->product_name ?? '',
                    $qty,
                    number_format($effectivePrice, 2, '.', ','),
                    number_format($subtotalUsd, 2, '.', ','),
                    number_format($subtotalCop, 0, '.', ','),
                    number_format($trm, 2, '.', ','),
                    $isSample ? 'Sí' : 'No',
                    ((int)($partial->new_win ?? 0) === 1) ? 'Sí' : 'No',
                ];

                $this->appendRow($accessToken, $sheetId, 'Ítems', $itemRow);
            }

            // Si no hay partials reales, calcular desde el pivot como fallback
            if ($partials->isEmpty()) {
                foreach ($order->products as $product) {
                    $isSample = (bool) ($product->pivot->muestra ?? false);
                    $pivotPrice = (float) ($product->pivot->price ?? 0);
                    $effectivePrice = $isSample ? 0.0
                        : ($pivotPrice > 0 ? $pivotPrice : (float) ($product->price ?? 0));
                    $qty = (int) ($product->pivot->quantity ?? 0);
                    $totalUsd += $effectivePrice * $qty;
                }
                $totalCop = $totalUsd * ($orderTrm > 0 ? $orderTrm : 4000.0);
            }

            // ── Hoja Órdenes (una fila por OC) ──
            $trm = $orderTrm > 0 ? $orderTrm : 4000.0;
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
                $invoiceNum,
                $trackingNum,
                $transporter,
                $order->is_muestra ? 'Sí' : 'No',
                $order->is_new_win  ? 'Sí' : 'No',
                $order->project?->name ?? '',
                $order->contact ?? '',
                $order->delivery_address ?? '',
            ];

            $this->appendRow($accessToken, $sheetId, 'Órdenes', $orderRow);

            // Marcar como exportada para evitar duplicados futuros
            $exports[] = $exportKey;
            $order->updateQuietly(['sheets_exports' => $exports]);
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

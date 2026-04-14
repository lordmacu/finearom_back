<?php

namespace App\Http\Controllers;

use App\Models\SiigoClient;
use App\Models\SiigoProduct;
use App\Models\SiigoMovement;
use App\Models\SiigoCartera;
use App\Models\SiigoSyncLog;
use App\Models\SiigoWebhookLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiigoSyncController extends Controller
{
    /**
     * Login para el middleware Go.
     * POST /api/siigo/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        if (!$user || !\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken('siigo-sync')->plainTextToken;

        return response()->json([
            'token' => $token,
            'message' => 'Login exitoso',
        ]);
    }

    /**
     * Sync clients from Siigo.
     * POST /api/siigo/clients
     */
    public function syncClients(Request $request)
    {
        $request->validate([
            'nit' => 'required|string',
            'client_name' => 'nullable|string',
            'business_name' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|string',
            'tipo_doc' => 'nullable|string',
            'siigo_codigo' => 'nullable|string',
            'siigo_sync_hash' => 'nullable|string',
        ]);

        $client = SiigoClient::updateOrCreate(
            ['nit' => $request->nit],
            [
                'nombre' => mb_substr($request->client_name ?? $request->business_name ?? '', 0, 100) ?: null,
                'tipo_doc' => $request->tipo_doc,
                'numero_doc' => $request->nit,
                'direccion' => $request->address,
                'ciudad' => $request->city,
                'telefono' => $request->phone,
                'email' => $request->email,
                'siigo_codigo' => $request->siigo_codigo,
                'siigo_hash' => $request->siigo_sync_hash,
            ]
        );

        $this->logSync('Z17', $client->wasRecentlyCreated ? 'new' : 'updated', 1);

        return response()->json([
            'message' => 'Cliente sincronizado',
            'id' => $client->id,
            'created' => $client->wasRecentlyCreated,
        ]);
    }

    /**
     * Sync products from Siigo.
     * POST /api/siigo/products
     */
    public function syncProducts(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'product_name' => 'nullable|string',
            'price' => 'nullable|numeric',
            'siigo_sync_hash' => 'nullable|string',
        ]);

        $product = SiigoProduct::updateOrCreate(
            ['codigo' => $request->code],
            [
                'nombre' => $request->product_name ? mb_substr($request->product_name, 0, 120) : null,
                'precio' => $request->price ?? 0,
                'siigo_hash' => $request->siigo_sync_hash,
            ]
        );

        $this->logSync('Z06', $product->wasRecentlyCreated ? 'new' : 'updated', 1);

        return response()->json([
            'message' => 'Producto sincronizado',
            'id' => $product->id,
            'created' => $product->wasRecentlyCreated,
        ]);
    }

    /**
     * Sync movements from Siigo.
     * POST /api/siigo/movements
     */
    public function syncMovements(Request $request)
    {
        $request->validate([
            'tipo_comprobante' => 'nullable|string',
            'numero_doc' => 'nullable|string',
            'fecha' => 'nullable|date',
            'nit_tercero' => 'nullable|string',
            'cuenta_contable' => 'nullable|string',
            'descripcion' => 'nullable|string',
            'valor' => 'nullable|numeric',
            'tipo_mov' => 'nullable|string',
            'siigo_sync_hash' => 'nullable|string',
        ]);

        $movement = SiigoMovement::updateOrCreate(
            ['siigo_hash' => $request->siigo_sync_hash],
            [
                'tipo_comprobante' => $request->tipo_comprobante,
                'numero_doc' => $request->numero_doc,
                'fecha' => $request->fecha,
                'nit_tercero' => $request->nit_tercero,
                'cuenta_contable' => $request->cuenta_contable,
                'descripcion' => $request->descripcion ? mb_substr($request->descripcion, 0, 200) : null,
                'valor' => $request->valor ?? 0,
                'tipo_mov' => $request->tipo_mov,
            ]
        );

        $this->logSync('Z49', $movement->wasRecentlyCreated ? 'new' : 'updated', 1);

        return response()->json([
            'message' => 'Movimiento sincronizado',
            'id' => $movement->id,
            'created' => $movement->wasRecentlyCreated,
        ]);
    }

    /**
     * Sync cartera from Siigo.
     * POST /api/siigo/cartera
     */
    public function syncCartera(Request $request)
    {
        $request->validate([
            'tipo_registro' => 'nullable|string',
            'nit_tercero' => 'nullable|string',
            'cuenta_contable' => 'nullable|string',
            'fecha' => 'nullable|date',
            'descripcion' => 'nullable|string',
            'tipo_mov' => 'nullable|string',
            'siigo_sync_hash' => 'nullable|string',
        ]);

        $cartera = SiigoCartera::updateOrCreate(
            ['siigo_hash' => $request->siigo_sync_hash],
            [
                'tipo_registro' => $request->tipo_registro,
                'nit_tercero' => $request->nit_tercero,
                'cuenta_contable' => $request->cuenta_contable,
                'fecha' => $request->fecha,
                'descripcion' => $request->descripcion ? mb_substr($request->descripcion, 0, 200) : null,
                'tipo_mov' => $request->tipo_mov,
            ]
        );

        $this->logSync('Z09', $cartera->wasRecentlyCreated ? 'new' : 'updated', 1);

        return response()->json([
            'message' => 'Cartera sincronizada',
            'id' => $cartera->id,
            'created' => $cartera->wasRecentlyCreated,
        ]);
    }

    /**
     * Bulk sync - receive multiple records at once.
     * POST /api/siigo/bulk
     */
    public function bulk(Request $request)
    {
        $request->validate([
            'type' => 'required|in:clients,products,movements,cartera',
            'records' => 'required|array|min:1',
        ]);

        $type = $request->type;
        $records = $request->records;
        $created = 0;
        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($records as $record) {
                $item = $this->upsertRecord($type, $record);

                if ($item->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();

            $fileMap = ['clients' => 'Z17', 'products' => 'Z06', 'movements' => 'Z49', 'cartera' => 'Z09'];
            $this->logSync($fileMap[$type], 'bulk', $created + $updated, "created=$created, updated=$updated");

            return response()->json([
                'message' => 'Sincronización bulk completada',
                'created' => $created,
                'updated' => $updated,
                'total' => count($records),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generic sync endpoint - receives a single record from Go middleware.
     * POST /api/siigo/sync
     *
     * Payload: { "table": "clients|products|movements|cartera", "action": "add|edit|delete", "key": "...", "data": {...} }
     * This is the primary endpoint the Go middleware calls for each pending record.
     */
    public function sync(Request $request)
    {
        Log::channel('daily')->info('[siigo:sync] incoming request', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        $request->validate([
            'table' => 'required|in:clients,products,movements,cartera',
            'action' => 'required|in:add,edit,delete',
            'key' => 'nullable|string',
            'data' => 'required|array',
        ]);

        $table = $request->input('table');
        $action = $request->input('action');
        $key = $request->input('key', '');
        $data = $request->input('data');

        // Handle delete action
        if ($action === 'delete') {
            $deleted = $this->deleteRecord($table, $key, $data);
            $fileMap = ['clients' => 'Z17', 'products' => 'Z06', 'movements' => 'Z49', 'cartera' => 'Z09'];
            $this->logSync($fileMap[$table] ?? $table, 'deleted', $deleted ? 1 : 0, "key=$key");

            return response()->json([
                'message' => $deleted ? 'Registro eliminado' : 'Registro no encontrado',
                'deleted' => $deleted,
            ]);
        }

        // Add or edit: upsert the record
        $item = $this->upsertRecord($table, $data);
        $fileMap = ['clients' => 'Z17', 'products' => 'Z06', 'movements' => 'Z49', 'cartera' => 'Z09'];
        $this->logSync(
            $fileMap[$table] ?? $table,
            $item->wasRecentlyCreated ? 'new' : 'updated',
            1,
            "key=$key, action=$action"
        );

        return response()->json([
            'message' => 'Registro sincronizado',
            'id' => $item->id,
            'created' => $item->wasRecentlyCreated,
            'table' => $table,
        ]);
    }

    /**
     * Webhook endpoint - receives notifications from Go middleware.
     * POST /api/siigo/webhook
     *
     * Verifies HMAC-SHA256 signature if X-Webhook-Signature header present.
     * Dispatches based on event type: sync_complete triggers data pull,
     * send_complete/send_paused are logged for monitoring.
     */
    public function webhook(Request $request)
    {
        // Verify HMAC signature if configured
        $webhookSecret = config('services.siigo.webhook_secret');
        if ($webhookSecret) {
            $signature = $request->header('X-Webhook-Signature');
            if (!$signature) {
                return response()->json(['message' => 'Missing signature'], 401);
            }

            $expectedSig = 'sha256=' . hash_hmac('sha256', $request->getContent(), $webhookSecret);
            if (!hash_equals($expectedSig, $signature)) {
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $event = $request->input('event', 'unknown');
        $data = $request->input('data', []);
        $timestamp = $request->input('timestamp');

        // Log the webhook
        $log = SiigoWebhookLog::create([
            'event' => $event,
            'source_ip' => $request->ip(),
            'payload' => $request->all(),
            'status' => 'received',
        ]);

        try {
            $result = $this->processWebhookEvent($event, $data);
            $log->update(['status' => 'processed']);

            return response()->json([
                'message' => 'Webhook procesado',
                'event' => $event,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            $log->update(['status' => 'error', 'error_message' => $e->getMessage()]);

            return response()->json([
                'message' => 'Error procesando webhook',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ejecuta siigo:sync-cartera y luego siigo:sync-recaudos.
     * POST /api/siigo/sync-cartera-recaudos
     */
    public function syncCarteraRecaudos(): JsonResponse
    {
        Artisan::call('siigo:sync-cartera');
        $carteraOutput = trim(Artisan::output());

        Artisan::call('siigo:sync-recaudos');
        $recaudosOutput = trim(Artisan::output());

        return response()->json([
            'message' => 'Sincronización de cartera y recaudos completada',
            'cartera' => $carteraOutput,
            'recaudos' => $recaudosOutput,
        ]);
    }

    /**
     * Ejecuta siigo:sync-sales desde el primer día del mes hasta hoy.
     * POST /api/siigo/sync-sales
     */
    public function syncSales(): JsonResponse
    {
        $desde = Carbon::now()->startOfMonth()->format('Y-m');
        $hasta = Carbon::now()->format('Y-m');

        Artisan::call('siigo:sync-sales', [
            '--desde' => $desde,
            '--hasta' => $hasta,
        ]);

        $output = Artisan::output();

        return response()->json([
            'message' => 'Sincronización de ventas completada',
            'desde'   => $desde,
            'hasta'   => $hasta,
            'output'  => trim($output),
        ]);
    }

    /**
     * Get sync status and recent logs.
     * GET /api/siigo/status
     */
    public function status()
    {
        return response()->json([
            'clients_count' => SiigoClient::count(),
            'products_count' => SiigoProduct::count(),
            'movements_count' => SiigoMovement::count(),
            'cartera_count' => SiigoCartera::count(),
            'recent_logs' => SiigoSyncLog::latest()->take(20)->get(),
            'recent_webhooks' => SiigoWebhookLog::latest()->take(10)->get(),
        ]);
    }

    /**
     * Registrar/actualizar la URL del tunnel publico del Siigo Bridge.
     * El bridge llama este endpoint cada vez que genera una URL nueva.
     * POST /api/siigo/tunnel
     */
    public function updateTunnelUrl(Request $request)
    {
        $request->validate([
            'tunnel_url' => 'required|url',
            'version' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = $user ? $user->id : null;
        $email = $user ? $user->email : 'unknown';

        // Guardar en la tabla siigo_bridge_tunnels (o actualizar si existe)
        DB::table('siigo_bridge_tunnels')->updateOrInsert(
            ['user_id' => $userId],
            [
                'user_email' => $email,
                'tunnel_url' => $request->tunnel_url,
                'version' => $request->version,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->logSync('TUNNEL', 'updated', 1, 'URL: ' . $request->tunnel_url);

        return response()->json([
            'message' => 'Tunnel URL actualizado',
            'tunnel_url' => $request->tunnel_url,
        ]);
    }

    /**
     * Obtener la URL actual del tunnel para un usuario dado.
     * GET /api/siigo/tunnel
     */
    public function getTunnelUrl(Request $request)
    {
        $user = $request->user();
        $record = DB::table('siigo_bridge_tunnels')
            ->where('user_id', $user->id)
            ->first();

        if (!$record) {
            return response()->json([
                'tunnel_url' => null,
                'message' => 'No hay URL registrada',
            ], 404);
        }

        return response()->json([
            'tunnel_url' => $record->tunnel_url,
            'version' => $record->version,
            'updated_at' => $record->updated_at,
        ]);
    }

    /**
     * List synced clients.
     * GET /api/siigo/clients
     */
    public function listClients(Request $request)
    {
        $query = SiigoClient::query();
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('nit', 'like', "%{$search}%");
            });
        }
        return response()->json($query->orderBy('updated_at', 'desc')->paginate(50));
    }

    /**
     * List synced products.
     * GET /api/siigo/products
     */
    public function listProducts(Request $request)
    {
        $query = SiigoProduct::query();
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }
        return response()->json($query->orderBy('updated_at', 'desc')->paginate(50));
    }

    /**
     * List synced movements.
     * GET /api/siigo/movements
     */
    public function listMovements(Request $request)
    {
        $query = SiigoMovement::query();
        if ($request->tipo) {
            $query->where('tipo_comprobante', $request->tipo);
        }
        if ($request->nit) {
            $query->where('nit_tercero', $request->nit);
        }
        return response()->json($query->orderBy('fecha', 'desc')->paginate(50));
    }

    /**
     * List synced cartera.
     * GET /api/siigo/cartera
     */
    public function listCartera(Request $request)
    {
        $query = SiigoCartera::query();
        if ($request->nit) {
            $query->where('nit_tercero', $request->nit);
        }
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nit_tercero', 'like', "%{$search}%")
                  ->orWhere('descripcion', 'like', "%{$search}%")
                  ->orWhere('cuenta_contable', 'like', "%{$search}%");
            });
        }
        return response()->json($query->orderBy('fecha', 'desc')->paginate(50));
    }

    /**
     * Webhook logs.
     * GET /api/siigo/webhook-logs
     */
    public function webhookLogs(Request $request)
    {
        return response()->json(
            SiigoWebhookLog::latest()->paginate($request->input('per_page', 50))
        );
    }

    // ===== Private helpers =====

    /**
     * Process a webhook event and dispatch to the appropriate handler.
     */
    private function processWebhookEvent(string $event, array $data): array
    {
        switch ($event) {
            case 'sync_complete':
                // A detect cycle just finished - log the stats
                $this->logSync('webhook', 'sync_complete', 0, json_encode($data));
                return ['action' => 'logged', 'stats' => $data];

            case 'send_complete':
                $sent = $data['sent'] ?? 0;
                $errors = $data['errors'] ?? 0;
                $this->logSync('webhook', 'send_complete', $sent, "sent=$sent, errors=$errors");
                return ['action' => 'logged', 'sent' => $sent, 'errors' => $errors];

            case 'send_paused':
                $reason = $data['reason'] ?? 'unknown';
                $this->logSync('webhook', 'send_paused', 0, "reason=$reason");
                return ['action' => 'alert_logged', 'reason' => $reason];

            case 'record_change':
                // A specific record changed - could trigger specific business logic
                $table = $data['table'] ?? '';
                $key = $data['key'] ?? '';
                $action = $data['action'] ?? '';
                $this->logSync('webhook', "record_{$action}", 1, "table=$table, key=$key");
                return ['action' => 'logged', 'table' => $table, 'key' => $key];

            case 'test':
                return ['action' => 'test_ok'];

            default:
                $this->logSync('webhook', $event, 0, json_encode($data));
                return ['action' => 'logged_unknown', 'event' => $event];
        }
    }

    /**
     * Upsert a single record based on type.
     */
    private function upsertRecord(string $type, array $record)
    {
        switch ($type) {
            case 'clients':
                return SiigoClient::updateOrCreate(
                    ['nit' => $record['nit']],
                    [
                        'nombre' => $record['client_name'] ?? $record['business_name'] ?? null,
                        'tipo_doc' => $record['tipo_doc'] ?? null,
                        'numero_doc' => $record['nit'],
                        'direccion' => $record['address'] ?? null,
                        'ciudad' => $record['city'] ?? null,
                        'telefono' => $record['phone'] ?? null,
                        'email' => $record['email'] ?? null,
                        'siigo_codigo' => $record['siigo_codigo'] ?? null,
                        'siigo_hash' => $record['siigo_sync_hash'] ?? null,
                    ]
                );

            case 'products':
                return SiigoProduct::updateOrCreate(
                    ['codigo' => $record['code']],
                    [
                        'nombre' => $record['product_name'] ?? null,
                        'nombre_corto' => $record['nombre_corto'] ?? null,
                        'grupo' => $record['grupo'] ?? null,
                        'referencia' => $record['referencia'] ?? null,
                        'empresa' => $record['empresa'] ?? null,
                        'siigo_hash' => $record['siigo_sync_hash'] ?? null,
                    ]
                );

            case 'movements':
                return SiigoMovement::updateOrCreate(
                    ['siigo_hash' => $record['siigo_sync_hash'] ?? uniqid()],
                    [
                        'tipo_comprobante' => $record['tipo_comprobante'] ?? null,
                        'numero_doc' => $record['numero_doc'] ?? null,
                        'fecha' => $record['fecha'] ?? null,
                        'nit_tercero' => $record['nit_tercero'] ?? null,
                        'cuenta_contable' => $record['cuenta_contable'] ?? null,
                        'descripcion' => $record['descripcion'] ?? null,
                        'valor' => $record['valor'] ?? 0,
                        'tipo_mov' => $record['tipo_mov'] ?? null,
                    ]
                );

            case 'cartera':
                return SiigoCartera::updateOrCreate(
                    ['siigo_hash' => $record['siigo_sync_hash'] ?? uniqid()],
                    [
                        'tipo_registro' => $record['tipo_registro'] ?? null,
                        'nit_tercero' => $record['nit_tercero'] ?? null,
                        'cuenta_contable' => $record['cuenta_contable'] ?? null,
                        'fecha' => $record['fecha'] ?? null,
                        'descripcion' => $record['descripcion'] ?? null,
                        'tipo_mov' => $record['tipo_mov'] ?? null,
                    ]
                );

            default:
                throw new \InvalidArgumentException("Tipo no soportado: $type");
        }
    }

    /**
     * Delete a record by key.
     */
    private function deleteRecord(string $table, string $key, array $data): bool
    {
        switch ($table) {
            case 'clients':
                return SiigoClient::where('nit', $key)->delete() > 0;
            case 'products':
                return SiigoProduct::where('codigo', $key)->delete() > 0;
            case 'movements':
                $hash = $data['siigo_sync_hash'] ?? $key;
                return SiigoMovement::where('siigo_hash', $hash)->delete() > 0;
            case 'cartera':
                $hash = $data['siigo_sync_hash'] ?? $key;
                return SiigoCartera::where('siigo_hash', $hash)->delete() > 0;
            default:
                return false;
        }
    }

    private function logSync(string $fileName, string $action, int $count, ?string $details = null): void
    {
        SiigoSyncLog::create([
            'file_name' => $fileName,
            'action' => $action,
            'records_count' => $count,
            'details' => $details,
        ]);
    }
}

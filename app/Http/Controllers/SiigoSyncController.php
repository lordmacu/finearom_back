<?php

namespace App\Http\Controllers;

use App\Models\SiigoClient;
use App\Models\SiigoProduct;
use App\Models\SiigoMovement;
use App\Models\SiigoSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'nombre' => $request->client_name ?? $request->business_name,
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
                'nombre' => $request->product_name,
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
                'descripcion' => $request->descripcion,
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
     * Bulk sync - receive multiple records at once.
     * POST /api/siigo/bulk
     */
    public function bulk(Request $request)
    {
        $request->validate([
            'type' => 'required|in:clients,products,movements',
            'records' => 'required|array|min:1',
        ]);

        $type = $request->type;
        $records = $request->records;
        $created = 0;
        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($records as $record) {
                switch ($type) {
                    case 'clients':
                        $item = SiigoClient::updateOrCreate(
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
                        break;

                    case 'products':
                        $item = SiigoProduct::updateOrCreate(
                            ['codigo' => $record['code']],
                            [
                                'nombre' => $record['product_name'] ?? null,
                                'precio' => $record['price'] ?? 0,
                                'siigo_hash' => $record['siigo_sync_hash'] ?? null,
                            ]
                        );
                        break;

                    case 'movements':
                        $item = SiigoMovement::updateOrCreate(
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
                        break;
                }

                if ($item->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();

            $fileMap = ['clients' => 'Z17', 'products' => 'Z06', 'movements' => 'Z49'];
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
     * Get sync status and recent logs.
     * GET /api/siigo/status
     */
    public function status()
    {
        return response()->json([
            'clients_count' => SiigoClient::count(),
            'products_count' => SiigoProduct::count(),
            'movements_count' => SiigoMovement::count(),
            'recent_logs' => SiigoSyncLog::latest()->take(20)->get(),
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
            $query->where('nombre', 'like', "%{$request->search}%")
                  ->orWhere('nit', 'like', "%{$request->search}%");
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
            $query->where('nombre', 'like', "%{$request->search}%")
                  ->orWhere('codigo', 'like', "%{$request->search}%");
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

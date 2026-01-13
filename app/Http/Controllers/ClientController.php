<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchOffice\BranchOfficeStoreRequest;
use App\Http\Requests\BranchOffice\BranchOfficeUpdateRequest;
use App\Http\Requests\Client\ClientStoreRequest;
use App\Http\Requests\Client\ClientUpdateRequest;
use App\Http\Requests\Client\ClientAutocreationRequest;
use App\Http\Requests\Executive\ExecutiveStoreRequest;
use App\Http\Requests\Executive\ExecutiveUpdateRequest;
use App\Models\BranchOffice;
use App\Models\Client;
use App\Models\Executive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Mail\ClientAutoCreationMail;
use App\Mail\ClientAutofillMail;
use App\Mail\ClientWelcomeMail;
use App\Queries\Cartera\CarteraQuery;

class ClientController extends Controller
{
    public function __construct(
        private readonly ?CarteraQuery $carteraQuery = null
    ) {
        $this->middleware('can:client list')->only(['index', 'exportClients', 'exportOffices', 'branchOffices', 'executives']);
        $this->middleware('can:client create')->only(['store', 'importClients', 'importOffices', 'autocreation']);
        $this->middleware('can:client edit')->only(['update', 'saveBranchOffice', 'saveExecutive', 'bulkAutofill']);
        $this->middleware('can:client delete')->only(['destroy', 'deleteBranchOffice', 'deleteExecutive']);
    }

    public function index(Request $request): JsonResponse
    {
        // Generar clave de caché única basada en los parámetros de la consulta
        $cacheKey = $this->generateClientsCacheKey($request);
        $cacheTimestampKey = $cacheKey . '.timestamp';

        // Verificar si el caché es válido comparando timestamps
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $lastModified = Cache::get('clients.last_modified', 0);

        if ($cachedTimestamp && $cachedTimestamp < $lastModified) {
            // El caché está desactualizado, eliminarlo
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché permanente (solo se invalida manualmente en CRUD)
        $data = Cache::rememberForever($cacheKey, function () use ($request) {
            $query = Client::query();

            if ($search = $request->query('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('client_name', 'like', "%{$search}%")
                        ->orWhere('nit', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($exec = $request->query('executive_email')) {
                $query->where('executive_email', $exec);
            }

            $allowedSorts = ['id', 'client_name', 'nit', 'email', 'executive_email'];
            $sortBy = $request->query('sort_by', 'id');
            $sortDir = strtolower($request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
            if (! in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            $query->orderBy($sortBy, $sortDir);

            $perPage = max(1, min(200, (int) $request->query('per_page', 15)));
            $paginate = filter_var($request->query('paginate', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($paginate === false) {
                $items = $query->limit($perPage)->get();
                $items->transform(function ($client) {
                    $client->autofill_link = $this->buildAutofillLink($client->id);
                    return $client;
                });
                return [
                    'success' => true,
                    'data' => $items,
                    'meta' => [
                        'total' => $items->count(),
                        'paginate' => false,
                    ],
                ];
            }

            $clients = $query->paginate($perPage);

            $items = collect($clients->items())->map(function ($client) {
                $client->autofill_link = $this->buildAutofillLink($client->id);
                return $client;
            });

            return [
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                    'paginate' => true,
                ],
            ];
        });

        // Guardar timestamp del caché si es la primera vez (permanente)
        if (!Cache::has($cacheTimestampKey)) {
            Cache::forever($cacheTimestampKey, now()->timestamp);
        }

        return response()->json($data);
    }

    public function show(int $clientId): JsonResponse
    {
        $cacheKey = "clients.show.{$clientId}";
        $cacheTimestampKey = "{$cacheKey}.timestamp";

        // Verificar si el caché es válido comparando timestamps
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $lastModified = Cache::get('clients.last_modified', 0);

        if ($cachedTimestamp && $cachedTimestamp < $lastModified) {
            // El caché está desactualizado, eliminarlo
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché permanente (solo se invalida manualmente en CRUD)
        $client = Cache::rememberForever($cacheKey, function () use ($clientId) {
            return Client::query()->findOrFail($clientId);
        });

        // Guardar timestamp del caché si es la primera vez (permanente)
        if (!Cache::has($cacheTimestampKey)) {
            Cache::forever($cacheTimestampKey, now()->timestamp);
        }

        return response()->json([
            'success' => true,
            'data' => $client,
        ]);
    }

    public function store(ClientStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $data = $this->prepareClientPayload($validated, $request);

        // Crear email único para el usuario
        $emailForUser = $validated['email'];
        if (User::where('email', $emailForUser)->exists()) {
            $emailForUser = $validated['email'] . '_' . now()->timestamp;
        }

        // Crear el usuario
        $user = User::create([
            'name' => $validated['client_name'],
            'email' => $emailForUser,
            'password' => Hash::make('password'),
        ]);

        // Asignar rol
        $role = Role::where('name', 'Creador de Ordenes de Compra')->first();
        if ($role) {
            $user->assignRole($role);
        }

        // Vincular usuario al cliente
        $data['user_id'] = $user->id;
        $client = Client::create($data);

        // Invalidar caché de clientes
        $this->clearClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado exitosamente con usuario vinculado',
            'data' => $client,
        ], 201);
    }

    public function update(ClientUpdateRequest $request, int $clientId): JsonResponse
    {
        $client = Client::query()->findOrFail($clientId);
        $data = $this->prepareClientPayload($request->validated(), $request);
        $client->update($data);

        // Invalidar caché de clientes
        $this->clearClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado',
            'data' => $client,
        ]);
    }

    public function destroy(int $clientId): JsonResponse
    {
        $client = Client::query()->findOrFail($clientId);
        $client->delete();

        // Invalidar caché de clientes
        $this->clearClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado',
        ]);
    }

    public function branchOffices(int $clientId): JsonResponse
    {
        $cacheKey = "branch_offices.client.{$clientId}";
        $cacheTimestampKey = "{$cacheKey}.timestamp";

        // Verificar si el caché es válido comparando timestamps
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $lastModified = Cache::get('branch_offices.last_modified', 0);

        if ($cachedTimestamp && $cachedTimestamp < $lastModified) {
            // El caché está desactualizado, eliminarlo
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché permanente (solo se invalida manualmente en CRUD)
        $offices = Cache::rememberForever($cacheKey, function () use ($clientId) {
            $client = Client::query()->findOrFail($clientId);
            return BranchOffice::query()
                ->where('client_id', $client->id)
                ->orderBy('id')
                ->get();
        });

        // Guardar timestamp del caché si es la primera vez (permanente)
        if (!Cache::has($cacheTimestampKey)) {
            Cache::forever($cacheTimestampKey, now()->timestamp);
        }

        return response()->json([
            'success' => true,
            'data' => $offices,
        ]);
    }

    public function saveBranchOffice(BranchOfficeStoreRequest $request, int $clientId): JsonResponse
    {
        $payload = $request->validated();
        $payload['client_id'] = $clientId;
        $office = BranchOffice::query()->create($payload);

        // Invalidar caché de sucursales
        $this->clearBranchOfficesCache();

        return response()->json([
            'success' => true,
            'message' => 'Sucursal creada',
            'data' => $office,
        ], 201);
    }

    public function updateBranchOffice(BranchOfficeUpdateRequest $request, int $clientId, int $officeId): JsonResponse
    {
        $office = BranchOffice::query()
            ->where('client_id', $clientId)
            ->findOrFail($officeId);

        $office->update($request->validated());

        // Invalidar caché de sucursales
        $this->clearBranchOfficesCache();

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada',
            'data' => $office,
        ]);
    }

    public function deleteBranchOffice(int $clientId, int $officeId): JsonResponse
    {
        $office = BranchOffice::query()
            ->where('client_id', $clientId)
            ->findOrFail($officeId);

        $office->delete();

        // Invalidar caché de sucursales
        $this->clearBranchOfficesCache();

        return response()->json([
            'success' => true,
            'message' => 'Sucursal eliminada',
        ]);
    }

    public function executives(): JsonResponse
    {
        $items = Executive::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function saveExecutive(ExecutiveStoreRequest $request): JsonResponse
    {
        $exec = Executive::query()->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Ejecutivo creado',
            'data' => $exec,
        ], 201);
    }

    public function updateExecutive(ExecutiveUpdateRequest $request, int $executiveId): JsonResponse
    {
        $exec = Executive::query()->findOrFail($executiveId);
        $exec->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Ejecutivo actualizado',
            'data' => $exec,
        ]);
    }

    public function deleteExecutive(int $executiveId): JsonResponse
    {
        $exec = Executive::query()->findOrFail($executiveId);
        $exec->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ejecutivo eliminado',
        ]);
    }

    public function importClients(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt'],
        ]);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            return response()->json(['success' => false, 'message' => 'Archivo sin datos'], 422);
        }

        $headers = array_map(function ($val) {
            return strtolower(trim((string) $val));
        }, $rows[1] ?? []);

        $colIndex = array_flip($headers);
        $required = ['client_name', 'nit', 'email'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $colIndex)) {
                return response()->json(['success' => false, 'message' => "Falta columna requerida: {$key}"], 422);
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            for ($i = 2; $i <= count($rows); $i++) {
                $row = $rows[$i];
                if (! is_array($row)) {
                    continue;
                }

                $nit = trim((string) ($row[$colIndex['nit'] + 1] ?? ''));
                $name = trim((string) ($row[$colIndex['client_name'] + 1] ?? ''));
                $email = trim((string) ($row[$colIndex['email'] + 1] ?? ''));

                if ($name === '' || $email === '') {
                    $errors[] = ['row' => $i, 'message' => 'client_name/email vacio'];
                    continue;
                }

                $payload = [
                    'client_name' => $name,
                    'nit' => $nit,
                    'email' => $email,
                    'executive_email' => isset($colIndex['executive_email']) ? trim((string) ($row[$colIndex['executive_email'] + 1] ?? '')) : null,
                    'dispatch_confirmation_email' => isset($colIndex['dispatch_confirmation_email']) ? trim((string) ($row[$colIndex['dispatch_confirmation_email'] + 1] ?? '')) : null,
                    'accounting_contact_email' => isset($colIndex['accounting_contact_email']) ? trim((string) ($row[$colIndex['accounting_contact_email'] + 1] ?? '')) : null,
                    'portfolio_contact_email' => isset($colIndex['portfolio_contact_email']) ? trim((string) ($row[$colIndex['portfolio_contact_email'] + 1] ?? '')) : null,
                    'compras_email' => isset($colIndex['compras_email']) ? trim((string) ($row[$colIndex['compras_email'] + 1] ?? '')) : null,
                    'logistics_email' => isset($colIndex['logistics_email']) ? trim((string) ($row[$colIndex['logistics_email'] + 1] ?? '')) : null,
                ];

                $client = Client::query()->where('nit', $nit)->first();
                if ($client) {
                    $client->update($payload);
                    $updated++;
                } else {
                    Client::query()->create($payload);
                    $created++;
                }
            }
            DB::commit();

            // Invalidar caché de clientes si hubo cambios
            if ($created > 0 || $updated > 0) {
                $this->clearClientsCache();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Importacion clientes procesada',
            'data' => compact('created', 'updated', 'errors'),
        ]);
    }

    public function importOffices(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt'],
        ]);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            return response()->json(['success' => false, 'message' => 'Archivo sin datos'], 422);
        }

        $headers = array_map(function ($val) {
            return strtolower(trim((string) $val));
        }, $rows[1] ?? []);

        $colIndex = array_flip($headers);
        $required = ['name', 'delivery_address', 'delivery_city'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $colIndex)) {
                return response()->json(['success' => false, 'message' => "Falta columna requerida: {$key}"], 422);
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            for ($i = 2; $i <= count($rows); $i++) {
                $row = $rows[$i];
                if (! is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row[$colIndex['name'] + 1] ?? ''));
                $deliveryAddress = trim((string) ($row[$colIndex['delivery_address'] + 1] ?? ''));
                $deliveryCity = trim((string) ($row[$colIndex['delivery_city'] + 1] ?? ''));
                $nit = isset($colIndex['nit']) ? trim((string) ($row[$colIndex['nit'] + 1] ?? '')) : null;

                $clientId = null;
                if (isset($colIndex['client_id'])) {
                    $clientId = (int) ($row[$colIndex['client_id'] + 1] ?? 0);
                } elseif ($nit) {
                    $clientId = Client::query()->where('nit', $nit)->value('id');
                }

                if (! $clientId) {
                    $errors[] = ['row' => $i, 'message' => 'Cliente no encontrado'];
                    continue;
                }

                $payload = [
                    'name' => $name,
                    'nit' => $nit,
                    'client_id' => $clientId,
                    'delivery_address' => $deliveryAddress,
                    'delivery_city' => $deliveryCity,
                    'general_observations' => isset($colIndex['general_observations']) ? trim((string) ($row[$colIndex['general_observations'] + 1] ?? '')) : '',
                ];

                $office = BranchOffice::query()
                    ->where('client_id', $clientId)
                    ->where('name', $name)
                    ->first();

                if ($office) {
                    $office->update($payload);
                    $updated++;
                } else {
                    BranchOffice::query()->create($payload);
                    $created++;
                }
            }

            DB::commit();

            // Invalidar caché de sucursales si hubo cambios
            if ($created > 0 || $updated > 0) {
                $this->clearBranchOfficesCache();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Importacion sucursales procesada',
            'data' => compact('created', 'updated', 'errors'),
        ]);
    }

    public function exportClients(Request $request): StreamedResponse
    {
        $query = Client::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                    ->orWhere('nit', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $allowedSorts = ['id', 'client_name', 'nit', 'email', 'executive_email'];
        $sortBy = $request->query('sort_by', 'id');
        $sortDir = strtolower($request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }
        $query->orderBy($sortBy, $sortDir);

        $callback = function () use ($query) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->fromArray([
                ['Client Name', 'NIT', 'Email', 'Executive Email', 'Dispatch Email', 'Accounting Email', 'Portfolio Email', 'Compras Email', 'Logistics Email', 'Operation'],
            ], null, 'A1', true);

            $rowNumber = 2;
            $query->chunk(500, function ($clients) use (&$rowNumber, $sheet) {
                foreach ($clients as $client) {
                    $sheet->setCellValue("A{$rowNumber}", $client->client_name);
                    $sheet->setCellValue("B{$rowNumber}", $client->nit);
                    $sheet->setCellValue("C{$rowNumber}", $client->email);
                    $sheet->setCellValue("D{$rowNumber}", $client->executive_email);
                    $sheet->setCellValue("E{$rowNumber}", $client->dispatch_confirmation_email);
                    $sheet->setCellValue("F{$rowNumber}", $client->accounting_contact_email);
                    $sheet->setCellValue("G{$rowNumber}", $client->portfolio_contact_email);
                    $sheet->setCellValue("H{$rowNumber}", $client->compras_email);
                    $sheet->setCellValue("I{$rowNumber}", $client->logistics_email);
                    $sheet->setCellValue("J{$rowNumber}", '');
                    $rowNumber++;
                }
            });

            $writer = new Xlsx($spreadsheet);
            $spreadsheet->setActiveSheetIndex(0);
            $writer->save('php://output');
        };

        $fileName = 'clients_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload($callback, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    public function exportOffices(Request $request): StreamedResponse
    {
        $query = BranchOffice::query()->with('client:id,client_name');

        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', (int) $clientId);
        }

        $callback = function () use ($query) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->fromArray([
                ['ID', 'NIT', 'Delivery Address', 'Delivery City', 'Client Name', 'Operation'],
            ], null, 'A1', true);

            $rowNumber = 2;
            $query->chunk(500, function ($offices) use (&$rowNumber, $sheet) {
                foreach ($offices as $office) {
                    $sheet->setCellValue("A{$rowNumber}", $office->id);
                    $sheet->setCellValue("B{$rowNumber}", $office->nit);
                    $sheet->setCellValue("C{$rowNumber}", $office->delivery_address);
                    $sheet->setCellValue("D{$rowNumber}", $office->delivery_city);
                    $sheet->setCellValue("E{$rowNumber}", optional($office->client)->client_name);
                    $sheet->setCellValue("F{$rowNumber}", '');
                    $rowNumber++;
                }
            });

            $writer = new Xlsx($spreadsheet);
            $spreadsheet->setActiveSheetIndex(0);
            $writer->save('php://output');
        };

        $fileName = 'branch_offices_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload($callback, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    public function autocreation(ClientAutocreationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $emailForUser = $data['email'];
        if (User::where('email', $emailForUser)->exists()) {
            $emailForUser = $data['email'] . '_' . now()->timestamp;
        }

        $user = User::create([
            'name' => $data['client_name'],
            'email' => $emailForUser,
            'password' => Hash::make('password'),
        ]);

        $role = Role::where('name', 'Creador de Ordenes de Compra')->first();
        if ($role) {
            $user->assignRole($role);
        }

        $client = Client::query()->create([
            'client_name' => $data['client_name'],
            'email' => $data['email'],
            'nit' => $data['nit'] ?? null,
            'executive_email' => $data['executive_email'] ?? null,
            'executive' => $data['executive_name'] ?? null,
            'executive_phone' => $data['executive_phone'] ?? null,
            'user_id' => $user->id,
            'client_type' => 'pareto',
            'payment_type' => 'cash',
            'proforma_invoice' => false,
            'payment_method' => 1,
            'payment_day' => 1,
            'status' => 'active',
            'dispatch_confirmation_email' => '',
            'accounting_contact_email' => '',
            'portfolio_contact_email' => '',
            'compras_email' => '',
            'logistics_email' => '',
            'address' => '',
            'city' => '',
            'billing_closure' => '',
            'commercial_conditions' => '',
        ]);

        BranchOffice::query()->create([
            'name' => ($data['client_name'] ?? 'Cliente') . ' - Sede Principal',
            'nit' => $data['nit'] ?? '',
            'client_id' => $client->id,
            'delivery_address' => '',
            'delivery_city' => '',
            'general_observations' => 'Sucursal creada automáticamente',
        ]);

        $link = $this->buildAutofillLink($client->id);

        $recipients = $this->collectClientEmails($client);

        if (! empty($recipients)) {
            Mail::to($recipients)->send(new ClientAutoCreationMail($client, $link));
        }

        // Invalidar caché de clientes
        $this->clearClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado y correo de autocreación enviado',
            'data' => [
                'client' => $client,
                'recipients' => $recipients,
                'link' => $link,
            ],
        ], 201);
    }

    public function generateAutofillLink(int $clientId): JsonResponse
    {
        $client = Client::query()->findOrFail($clientId);
        $token = encrypt($client->id);
        $base = config('app.frontend_url') ?? config('app.url');
        $url = rtrim($base, '/') . '/clients/autofill?token=' . $token;

        return response()->json([
            'success' => true,
            'data' => ['link' => $url],
        ]);
    }

    public function sendAutofillEmail(int $clientId): JsonResponse
    {
        $client = Client::query()->findOrFail($clientId);
        $link = $this->buildAutofillLink($client->id);

        $recipients = $this->collectClientEmails($client);
        if (empty($recipients)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay correos válidos para este cliente',
            ], 422);
        }

        Mail::to($recipients)->send(new ClientAutofillMail($client, $link));

        return response()->json([
            'success' => true,
            'message' => 'Correo de autollenado enviado',
            'data' => ['recipients' => $recipients, 'link' => $link],
        ]);
    }

    public function bulkAutofill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_ids' => ['required', 'array'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $base = config('app.frontend_url') ?? config('app.url');
        $sent = [];
        $errors = [];

        foreach ($validated['client_ids'] as $clientId) {
            $client = Client::query()->find($clientId);
            if (! $client) {
                $errors[] = ['client_id' => $clientId, 'message' => 'Cliente no encontrado'];
                continue;
            }

            $link = $this->buildAutofillLink($client->id, $base);
            $recipients = $this->collectClientEmails($client);

            if (empty($recipients)) {
                $errors[] = ['client_id' => $clientId, 'message' => 'Sin correos válidos'];
                continue;
            }

            try {
                Mail::to($recipients)->send(new ClientAutofillMail($client, $link));
                $sent[] = [
                    'client_id' => $client->id,
                    'client_name' => $client->client_name,
                    'recipients' => $recipients,
                    'link' => $link,
                ];
            } catch (\Throwable $e) {
                $errors[] = ['client_id' => $clientId, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Correos de autollenado procesados',
            'data' => [
                'sent' => $sent,
                'errors' => $errors,
            ],
        ]);
    }

    public function showByToken(string $token): JsonResponse
    {
        try {
            $clientId = decrypt($token);
            $client = Client::findOrFail($clientId);
            $offices = BranchOffice::where('client_id', $client->id)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'client' => $client,
                    'branch_offices' => $offices
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido o expirado',
            ], 403);
        }
    }

    public function updateByToken(ClientUpdateRequest $request, string $token): JsonResponse
    {
        try {
            $clientId = decrypt($token);
            $client = Client::findOrFail($clientId);
            
            $data = $this->prepareClientPayload($request->validated(), $request);
            $client->update($data);

            // Envío de correo de bienvenida interno
            try {
                $internalEmails = ['analista.operaciones@finearom.com', 'facturacion@finearom.com'];
                if ($client->executive_email) {
                    $internalEmails[] = $client->executive_email;
                }

                $emailData = [
                    'executive_name' => $client->executive,
                    'executive_email' => $client->executive_email,
                    'executive_phone' => $client->executive_phone,
                    'welcome_date' => now()->format('d/m/Y'),
                ];

                Mail::to($internalEmails)->send(new ClientWelcomeMail($client, $emailData, true));
            } catch (\Exception $e) {
                \Log::error('Error enviando correo de bienvenida: ' . $e->getMessage());
            }

            // Invalidar caché de clientes
            $this->clearClientsCache();

            return response()->json([
                'success' => true,
                'message' => 'Datos actualizados correctamente',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage(),
            ], 403);
        }
    }


    private function buildAutofillLink(int $clientId, ?string $base = null): string
    {
        $token = encrypt($clientId);
        $base = $base ?? (config('app.frontend_url') ?? config('app.url'));
        return rtrim($base, '/') . '/clients/autofill?token=' . $token;
    }

    private function prepareClientPayload(array $data, Request $request): array
    {
        // Default client_type since it's required by DB but not present in form
        if (!isset($data['client_type'])) {
            $data['client_type'] = 'pareto';
        }

        foreach (['proforma_invoice', 'is_in_free_zone', 'data_consent', 'marketing_consent'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $emailFields = [
            'email',
            'executive_email',
            'dispatch_confirmation_email',
            'accounting_contact_email',
            'portfolio_contact_email',
            'compras_email',
            'logistics_email',
            'purchasing_contact_email',
            'r_and_d_contact_email',
        ];

        foreach ($emailFields as $field) {
            if (array_key_exists($field, $data) && is_array($data[$field])) {
                $data[$field] = collect($data[$field])
                    ->filter(fn ($item) => filled($item))
                    ->implode(',');
            }
        }

        if (! isset($data['payment_type'])) {
            $paymentMethod = $data['payment_method'] ?? null;
            $data['payment_type'] = (string) $paymentMethod === '2' ? 'credit' : 'cash';
        }

        if (isset($data['commercial_terms']) && ! isset($data['commercial_conditions'])) {
            $data['commercial_conditions'] = $data['commercial_terms'];
        }

        if (isset($data['purchasing_contact_email']) && ! isset($data['compras_email'])) {
            $data['compras_email'] = $data['purchasing_contact_email'];
        }

        if (isset($data['logistics_contact_email']) && ! isset($data['logistics_email'])) {
            $data['logistics_email'] = $data['logistics_contact_email'];
        }

        foreach ($this->fileFields() as $field => $path) {
            if ($request->hasFile($field)) {
                $data[$field] = $request->file($field)->store($path, 'public');
            }
        }

        return $data;
    }

    private function fileFields(): array
    {
        return [
            'rut_file' => 'clients/rut',
            'camara_comercio_file' => 'clients/camara',
            'cedula_representante_file' => 'clients/cedula',
            'declaracion_renta_file' => 'clients/renta',
            'estados_financieros_file' => 'clients/financieros',
        ];
    }

    private function collectClientEmails(Client $client): array
    {
        $raw = [$client->email];

        $emails = [];
        foreach ($raw as $value) {
            if (! $value) {
                continue;
            }
            // Permitir listas separadas por coma
            foreach (explode(',', $value) as $part) {
                $email = trim($part);
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Generar clave de caché única basada en los parámetros de la consulta
     */
    private function generateClientsCacheKey(Request $request): string
    {
        $params = [
            'search' => $request->query('search', ''),
            'executive_email' => $request->query('executive_email', ''),
            'sort_by' => $request->query('sort_by', 'id'),
            'sort_direction' => $request->query('sort_direction', 'desc'),
            'per_page' => $request->query('per_page', 15),
            'paginate' => $request->query('paginate', true),
            'page' => $request->query('page', 1),
        ];

        return 'clients.index.' . md5(json_encode($params));
    }

    /**
     * Invalidar todas las claves de caché relacionadas con clientes
     */
    private function clearClientsCache(): void
    {
        // Guardar un timestamp de invalidación para verificar la frescura del caché
        Cache::forever('clients.last_modified', now()->timestamp);

        // Limpiar caché de cartera customers ya que depende de la tabla clients
        $this->carteraQuery?->clearCustomersCache();
    }

    /**
     * Invalidar todas las claves de caché relacionadas con sucursales
     */
    private function clearBranchOfficesCache(): void
    {
        // Guardar un timestamp de invalidación para verificar la frescura del caché
        Cache::forever('branch_offices.last_modified', now()->timestamp);
    }

}

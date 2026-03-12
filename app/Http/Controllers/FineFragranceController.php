<?php

namespace App\Http\Controllers;

use App\Http\Requests\FineFragrance\FineFragranceStoreRequest;
use App\Http\Requests\FineFragrance\FineFragranceUpdateRequest;
use App\Models\FineFragrance;
use App\Models\FineFragrancePriceHistory;
use App\Models\FineFragranceInventoryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FineFragranceController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:fine fragrance list')->only(['index', 'show']);
        $this->middleware('can:fine fragrance create')->only(['store', 'import']);
        $this->middleware('can:fine fragrance edit')->only(['update', 'updatePrice', 'addInventory', 'uploadPhoto']);
        $this->middleware('can:fine fragrance delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = FineFragrance::with('house');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contratipo', 'like', "%{$search}%")
                    ->orWhere('nombre', 'like', "%{$search}%");
            });
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        if ($houseId = $request->query('house_id')) {
            $query->where('fine_fragrance_house_id', $houseId);
        }

        $fragrances = $query->orderBy('contratipo')->paginate(30);

        return response()->json($fragrances, 200);
    }

    public function store(FineFragranceStoreRequest $request): JsonResponse
    {
        $fragrance = FineFragrance::create($request->validated());

        $fragrance->load('house');

        return response()->json(['data' => $fragrance, 'message' => 'Fragancia creada correctamente'], 201);
    }

    public function show(FineFragrance $fineFragrance): JsonResponse
    {
        $fineFragrance->load('house');

        return response()->json(['data' => $fineFragrance], 200);
    }

    public function update(FineFragranceUpdateRequest $request, FineFragrance $fineFragrance): JsonResponse
    {
        $fineFragrance->update($request->validated());

        $fineFragrance->load('house');

        return response()->json(['data' => $fineFragrance, 'message' => 'Fragancia actualizada correctamente'], 200);
    }

    public function destroy(FineFragrance $fineFragrance): JsonResponse
    {
        $fineFragrance->delete();

        return response()->json(['message' => 'Fragancia eliminada correctamente'], 200);
    }

    public function updatePrice(Request $request, FineFragrance $fineFragrance): JsonResponse
    {
        $validated = $request->validate([
            'precio_coleccion' => ['nullable', 'numeric', 'min:0'],
            'costo'            => ['nullable', 'numeric', 'min:0'],
            'precio_oferta'    => ['nullable', 'numeric', 'min:0'],
            'notas'            => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($validated, $fineFragrance) {
            FineFragrancePriceHistory::create([
                'fine_fragrance_id' => $fineFragrance->id,
                'precio_coleccion'  => $fineFragrance->precio_coleccion,
                'costo'             => $fineFragrance->costo,
                'precio_oferta'     => $fineFragrance->precio_oferta,
                'registrado_por'    => auth()->user()?->name,
                'notas'             => $validated['notas'] ?? 'Actualización de precios',
            ]);

            $fineFragrance->update([
                'precio_coleccion' => $validated['precio_coleccion'] ?? $fineFragrance->precio_coleccion,
                'costo'            => $validated['costo'] ?? $fineFragrance->costo,
                'precio_oferta'    => $validated['precio_oferta'] ?? $fineFragrance->precio_oferta,
            ]);
        });

        return response()->json(['data' => $fineFragrance->fresh(), 'message' => 'Precios actualizados correctamente'], 200);
    }

    public function addInventory(Request $request, FineFragrance $fineFragrance): JsonResponse
    {
        $validated = $request->validate([
            'tipo'        => ['required', 'string', 'in:entrada,salida,ajuste'],
            'cantidad_kg' => ['required', 'numeric', 'min:0.001'],
            'notas'       => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($validated, $fineFragrance) {
            $inventarioAnterior = $fineFragrance->inventario_kg;

            $inventarioNuevo = match ($validated['tipo']) {
                'entrada' => $inventarioAnterior + $validated['cantidad_kg'],
                'salida'  => max(0, $inventarioAnterior - $validated['cantidad_kg']),
                'ajuste'  => $validated['cantidad_kg'],
            };

            FineFragranceInventoryLog::create([
                'fine_fragrance_id'      => $fineFragrance->id,
                'tipo'                   => $validated['tipo'],
                'cantidad_kg'            => $validated['cantidad_kg'],
                'inventario_anterior_kg' => $inventarioAnterior,
                'inventario_nuevo_kg'    => $inventarioNuevo,
                'notas'                  => $validated['notas'] ?? null,
                'registrado_por'         => auth()->user()?->name,
            ]);

            $fineFragrance->update(['inventario_kg' => $inventarioNuevo]);
        });

        return response()->json(['data' => $fineFragrance->fresh(), 'message' => 'Movimiento de inventario registrado'], 200);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'                    => ['required', 'file', 'mimes:csv,txt'],
            'fine_fragrance_house_id' => ['required', 'integer', 'exists:fine_fragrance_houses,id'],
        ]);

        $houseId = $request->input('fine_fragrance_house_id');
        $file    = $request->file('file');
        $handle  = fopen($file->getRealPath(), 'r');

        $headers   = array_map('trim', fgetcsv($handle));
        $required  = ['contratipo', 'tipo'];
        $missing   = array_diff($required, $headers);

        if (!empty($missing)) {
            fclose($handle);
            return response()->json([
                'message' => 'Columnas requeridas faltantes: ' . implode(', ', $missing),
            ], 422);
        }

        $upserted = 0;
        $errors   = [];
        $row      = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;

            if (count($line) !== count($headers)) {
                $errors[] = "Fila {$row}: número de columnas incorrecto";
                continue;
            }

            $data = array_combine($headers, array_map('trim', $line));

            if (empty($data['contratipo']) || empty($data['tipo'])) {
                $errors[] = "Fila {$row}: contratipo y tipo son obligatorios";
                continue;
            }

            if (!in_array($data['tipo'], ['305', '310', '350'])) {
                $errors[] = "Fila {$row}: tipo inválido '{$data['tipo']}' (debe ser 305, 310 o 350)";
                continue;
            }

            $payload = [
                'fine_fragrance_house_id' => $houseId,
                'contratipo'              => $data['contratipo'],
                'tipo'                    => $data['tipo'],
            ];

            if (isset($data['precio_coleccion']) && $data['precio_coleccion'] !== '') {
                $payload['precio_coleccion'] = (float) $data['precio_coleccion'];
            }
            if (isset($data['costo']) && $data['costo'] !== '') {
                $payload['costo'] = (float) $data['costo'];
            }
            if (isset($data['inventario_kg']) && $data['inventario_kg'] !== '') {
                $payload['inventario_kg'] = (float) $data['inventario_kg'];
            }
            if (isset($data['precio_oferta']) && $data['precio_oferta'] !== '') {
                $payload['precio_oferta'] = (float) $data['precio_oferta'];
            }
            if (isset($data['estado']) && in_array($data['estado'], ['activa', 'inactiva', 'novedad', 'saldo'])) {
                $payload['estado'] = $data['estado'];
            }

            try {
                FineFragrance::updateOrCreate(
                    [
                        'fine_fragrance_house_id' => $houseId,
                        'contratipo'              => $data['contratipo'],
                        'tipo'                    => $data['tipo'],
                    ],
                    $payload
                );
                $upserted++;
            } catch (\Exception $e) {
                Log::error("Fine fragrance import error row {$row}: " . $e->getMessage());
                $errors[] = "Fila {$row}: error al guardar ({$e->getMessage()})";
            }
        }

        fclose($handle);

        return response()->json([
            'message'  => "Importación completada: {$upserted} registros procesados",
            'upserted' => $upserted,
            'errors'   => $errors,
        ], 200);
    }

    public function uploadPhoto(Request $request, FineFragrance $fineFragrance): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        if ($fineFragrance->foto_url && str_starts_with($fineFragrance->foto_url, '/storage/')) {
            Storage::disk('public')->delete(
                substr($fineFragrance->foto_url, strlen('/storage/'))
            );
        }

        $path = $request->file('photo')->store('fine-fragrances', 'public');
        $url  = '/storage/' . $path;

        $fineFragrance->update(['foto_url' => $url]);

        return response()->json([
            'data'    => $fineFragrance->fresh()->load('house'),
            'message' => 'Foto actualizada correctamente',
        ]);
    }
}

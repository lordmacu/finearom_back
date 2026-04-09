<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cartera\CarteraImportRequest;
use App\Http\Requests\Cartera\CarteraClientsRequest;
use App\Http\Requests\Cartera\CarteraFacturaHistoryRequest;
use App\Http\Requests\Cartera\CarteraStoreRequest;
use App\Http\Requests\Cartera\CarteraSummaryRequest;
use App\Models\Cartera;
use App\Queries\Cartera\CarteraQuery;
use App\Services\Cartera\CarteraPeriod;
use App\Services\Cartera\CarteraImportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarteraController extends Controller
{
    public function __construct(
        private readonly CarteraQuery $carteraQuery,
        private readonly CarteraPeriod $carteraPeriod,
        private readonly CarteraImportService $carteraImportService,
    )
    {
        $this->middleware('can:cartera view')->only([
            'summary',
            'clients',
            'executives',
            'customers',
            'invoiceHistory',
            'weeklyProjection',
        ]);

        $this->middleware('can:cartera import')->only(['import']);
        $this->middleware('can:cartera edit')->only(['store']);
    }

    public function summary(CarteraSummaryRequest $request): JsonResponse
    {
        [$from, $to] = $this->carteraPeriod->resolve($request->validated());
        $filters = $this->carteraQuery->filtersFromParams($request->validated());

        $data = $this->carteraQuery->summary($from, $to, $filters);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'snapshot_date' => $data['snapshot_date'] ?? null,
            ],
        ]);
    }

    public function clients(CarteraClientsRequest $request): JsonResponse
    {
        [$from, $to] = $this->carteraPeriod->resolve($request->validated());
        $filters = $this->carteraQuery->filtersFromParams($request->validated());
        $snapshotDate = $this->carteraQuery->latestSnapshotDate($filters['catera_type'] ?? null);

        $rows = $this->carteraQuery->clients($from, $to, $filters);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'total' => count($rows),
                'snapshot_date' => $snapshotDate,
            ],
        ]);
    }

    public function executives(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->carteraQuery->executives(),
        ]);
    }

    public function customers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->carteraQuery->customers(),
        ]);
    }

    public function invoiceHistory(CarteraFacturaHistoryRequest $request): JsonResponse
    {
        $documento = $request->validated()['documento'];
        $rows = $this->carteraQuery->invoiceHistory($documento);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'documento' => $documento,
                'total' => count($rows),
            ],
        ]);
    }

    /**
     * Proyección semanal de cobro: 3 semanas calendario desde hoy.
     *
     * Devuelve clientes nacionales agrupados con columnas por semana,
     * clientes exterior como lista plana, sección crítica (vencidos) y totales.
     */
    public function weeklyProjection(Request $request): JsonResponse
    {
        $filters = $this->carteraQuery->filtersFromParams($request->query());
        $data = $this->carteraQuery->weeklyProjection($filters);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Importa archivos Excel de cartera y retorna preview.
     *
     * Legacy equivalente: POST /admin/cartera/importar
     */
    public function import(CarteraImportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rows = $this->carteraImportService->importFiles(
            $request->file('files', []),
            (int) $validated['dias_mora'],
            (int) $validated['dias_cobro'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Archivos procesados exitosamente.',
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
            ],
        ]);
    }

    /**
     * Guarda un snapshot de cartera (fecha_cartera) con las filas seleccionadas.
     *
     * Legacy equivalente: POST /admin/cartera/guardar
     */
    public function store(CarteraStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $fechaCartera = Carbon::parse($validated['fecha'])->toDateString();
        $fechaFrom = Carbon::parse($validated['fechaFrom'])->toDateString();
        $fechaTo = Carbon::parse($validated['fechaTo'])->toDateString();

        $rows = $validated['cartera'];

        DB::transaction(function () use ($fechaCartera, $fechaFrom, $fechaTo, $rows) {
            Cartera::query()->where('fecha_cartera', $fechaCartera)->delete();

            $now = now();
            $payload = array_map(function (array $row) use ($fechaCartera, $fechaFrom, $fechaTo, $now) {
                $vendedor = (string) ($row['nombre_vendedor'] ?? $row['vendedor'] ?? 'N/A');

                return [
                    'nit' => $row['nit'],
                    'ciudad' => $row['ciudad'],
                    'vendedor' => $vendedor,
                    'nombre_vendedor' => $vendedor,
                    'cuenta' => $row['cuenta'],
                    'descripcion_cuenta' => $row['descripcion_cuenta'],
                    'documento' => $row['documento'],
                    'fecha' => $row['fecha'],
                    'fecha_from' => $fechaFrom,
                    'fecha_to' => $fechaTo,
                    'fecha_cartera' => $fechaCartera,
                    'vence' => $row['vence'],
                    'dias' => (int) $row['dias'],
                    'saldo_contable' => $row['saldo_contable'],
                    'vencido' => $row['vencido'],
                    'saldo_vencido' => $row['saldo_vencido'],
                    'nombre_empresa' => $row['nombre_empresa'],
                    'catera_type' => $row['catera_type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $rows);

            foreach (array_chunk($payload, 1000) as $chunk) {
                Cartera::query()->insert($chunk);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Cartera guardada exitosamente.',
            'data' => [
                'fecha_cartera' => $fechaCartera,
                'count' => count($rows),
            ],
        ], 201);
    }

    /**
     * Devuelve la ultima importacion de cartera (ultimo snapshot por fecha_cartera).
     * GET /api/cartera/latest-import
     */
    public function latestImport(Request $request): JsonResponse
    {
        $latest = Cartera::query()
            ->select('fecha_cartera', 'fecha_from', 'fecha_to')
            ->orderByDesc('created_at')
            ->first();

        if (! $latest) {
            return response()->json([
                'success' => true,
                'data' => [
                    'fecha_cartera' => null,
                    'fecha_from' => null,
                    'fecha_to' => null,
                    'rows' => [],
                    'totals' => ['count' => 0, 'saldo_contable' => 0, 'saldo_vencido' => 0],
                ],
            ]);
        }

        $rows = Cartera::query()
            ->where('fecha_cartera', $latest->fecha_cartera)
            ->orderBy('nit')
            ->orderBy('documento')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'fecha_cartera' => $latest->fecha_cartera,
                'fecha_from' => $latest->fecha_from,
                'fecha_to' => $latest->fecha_to,
                'rows' => $rows,
                'totals' => [
                    'count' => $rows->count(),
                    'saldo_contable' => (float) $rows->sum('saldo_contable'),
                    'saldo_vencido' => (float) $rows->sum('saldo_vencido'),
                    'vencido' => (float) $rows->sum('vencido'),
                ],
            ],
        ]);
    }

    /**
     * Actualizar un registro de cartera.
     * PUT /api/cartera/{id}
     */
    public function updateRow(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'nit' => ['nullable', 'string'],
            'ciudad' => ['nullable', 'string'],
            'vendedor' => ['nullable', 'string'],
            'nombre_vendedor' => ['nullable', 'string'],
            'cuenta' => ['nullable', 'string'],
            'descripcion_cuenta' => ['nullable', 'string'],
            'documento' => ['nullable', 'string'],
            'fecha' => ['nullable', 'date'],
            'vence' => ['nullable', 'date'],
            'dias' => ['nullable', 'integer'],
            'saldo_contable' => ['nullable', 'numeric'],
            'saldo_vencido' => ['nullable', 'numeric'],
            'vencido' => ['nullable', 'numeric'],
            'nombre_empresa' => ['nullable', 'string'],
        ]);

        $row = Cartera::findOrFail($id);
        $row->update($validated);

        $this->carteraQuery->clearCustomersCache();

        return response()->json([
            'success' => true,
            'message' => 'Registro actualizado correctamente',
            'data' => $row->fresh(),
        ]);
    }

    /**
     * Eliminar un registro de cartera.
     * DELETE /api/cartera/{id}
     */
    public function deleteRow(int $id): JsonResponse
    {
        $row = Cartera::findOrFail($id);
        $row->delete();

        $this->carteraQuery->clearCustomersCache();

        return response()->json([
            'success' => true,
            'message' => 'Registro eliminado correctamente',
        ]);
    }
}

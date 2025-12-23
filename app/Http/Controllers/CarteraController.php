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
}

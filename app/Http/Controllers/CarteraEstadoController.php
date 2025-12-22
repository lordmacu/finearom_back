<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cartera\CarteraEstadoLoadRequest;
use App\Http\Requests\Cartera\CarteraEstadoQueueRequest;
use App\Queries\Cartera\CarteraEstadoQuery;
use App\Services\Cartera\CarteraEmailQueueService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class CarteraEstadoController extends Controller
{
    public function __construct(
        private readonly CarteraEstadoQuery $carteraEstadoQuery,
        private readonly CarteraEmailQueueService $carteraEmailQueueService,
    )
    {
        $this->middleware('can:cartera estado view')->only(['load']);
        $this->middleware('can:cartera estado send')->only(['queue']);
    }

    public function load(CarteraEstadoLoadRequest $request): JsonResponse
    {
        $fecha = Carbon::parse($request->validated()['fecha'])->toDateString();
        $rows = $this->carteraEstadoQuery->loadByDate($fecha);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'fecha' => $fecha,
                'total' => count($rows),
            ],
        ]);
    }

    public function queue(CarteraEstadoQueueRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $fecha = Carbon::parse($validated['date'])->toDateString();

        $count = $this->carteraEmailQueueService->enqueue(
            $fecha,
            (string) $validated['type_queue'],
            $validated['data'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Correos agregados a la cola',
            'data' => [
                'count' => $count,
                'due_date' => $fecha,
                'type_queue' => $validated['type_queue'],
            ],
        ]);
    }
}


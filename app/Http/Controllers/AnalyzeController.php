<?php

namespace App\Http\Controllers;

use App\Http\Requests\Analyze\AnalyzeClientPartialsRequest;
use App\Http\Requests\Analyze\AnalyzeClientsRequest;
use App\Http\Requests\Analyze\UpdatePartialRequest;
use App\Models\Partial;
use App\Queries\Analyze\AnalyzeQuery;
use App\Services\Trm\TrmDailyWarmup;
use Illuminate\Http\JsonResponse;

class AnalyzeController extends Controller
{
    public function __construct(
        private readonly AnalyzeQuery $analyzeQuery,
        private readonly TrmDailyWarmup $trmDailyWarmup
    )
    {
        $this->middleware('can:analysis view')->only(['clients', 'clientPartials']);
        $this->middleware('can:partial edit')->only(['updatePartial']);
        $this->middleware('can:partial delete')->only(['deletePartial']);
    }

    public function clients(AnalyzeClientsRequest $request): JsonResponse
    {
        [$from, $to] = $this->analyzeQuery->resolveDateRange($request->validated());
        [$status, $type] = $this->analyzeQuery->resolveStatusAndType($request->validated());

        $paginateRaw = $request->validated()['paginate'] ?? null;
        $paginateParsed = $paginateRaw === null
            ? null
            : filter_var($paginateRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $paginate = $paginateParsed ?? true;
        $perPage = (int) ($request->validated()['per_page'] ?? 10);
        $perPage = max(1, min(5000, $perPage));

        $this->trmDailyWarmup->warmForAnalyze($from, $to, $type, $status);

        $totals = $this->analyzeQuery->totals($from, $to, $type, $status);
        if ($paginate) {
            $clients = $this->analyzeQuery->paginateClients($from, $to, $type, $status, $perPage);

            return response()->json([
                'success' => true,
                'data' => $clients->items(),
                'meta' => [
                    'current_page' => $clients->currentPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                    'last_page' => $clients->lastPage(),
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'paginate' => true,
                ],
                'totals' => [
                    'total_cop' => (float) ($totals->total_cop ?? 0),
                    'total_usd' => (float) ($totals->total_usd ?? 0),
                ],
            ]);
        }

        $clients = $this->analyzeQuery->allClients($from, $to, $type, $status, $perPage);

        return response()->json([
            'success' => true,
            'data' => $clients,
            'meta' => [
                'current_page' => 1,
                'per_page' => $perPage,
                'total' => $clients->count(),
                'last_page' => 1,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'paginate' => false,
            ],
            'totals' => [
                'total_cop' => (float) ($totals->total_cop ?? 0),
                'total_usd' => (float) ($totals->total_usd ?? 0),
            ],
        ]);
    }

    public function clientPartials(AnalyzeClientPartialsRequest $request, int $clientId): JsonResponse
    {
        [$from, $to] = $this->analyzeQuery->resolveDateRange($request->validated());
        [$status, $type] = $this->analyzeQuery->resolveStatusAndType($request->validated());

        $this->trmDailyWarmup->warmForAnalyze($from, $to, $type, $status, $clientId);
        $rows = $this->analyzeQuery->clientPartials($from, $to, $type, $status, $clientId);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function updatePartial(UpdatePartialRequest $request, int $partialId): JsonResponse
    {
        $partial = Partial::query()->findOrFail($partialId);

        $data = $request->validated();
        if (! empty($data['dispatch_date'])) {
            $partial->dispatch_date = $data['dispatch_date'];
        }

        $partial->quantity = $data['quantity'];
        $partial->trm = $data['trm'];
        $partial->touch();
        $partial->save();

        return response()->json([
            'success' => true,
            'message' => 'Parcial actualizado',
            'data' => [
                'id' => $partial->id,
                'quantity' => $partial->quantity,
                'trm' => $partial->trm,
                'dispatch_date' => $partial->dispatch_date,
                'updated_at' => $partial->updated_at,
            ],
        ]);
    }

    public function deletePartial(int $partialId): JsonResponse
    {
        $partial = Partial::query()->findOrFail($partialId);
        $partial->delete();

        return response()->json([
            'success' => true,
            'message' => 'Parcial eliminado',
        ]);
    }
}

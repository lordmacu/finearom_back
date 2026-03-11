<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyReportController extends Controller
{
    /**
     * Exporta el reporte mensual como JSON con:
     *   - ordenes: órdenes despachadas (completed + parcial_status) con sus productos embebidos
     *   - stats:   estadísticas del dashboard para el mismo período
     *
     * Parámetros opcionales:
     *   - start_date (Y-m-d)  — por defecto: inicio del mes actual
     *   - end_date   (Y-m-d)  — por defecto: fin del mes actual
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        try {
            return response()->json([
                'success' => true,
                'period'  => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes' => $this->buildOrdenes($startDate, $endDate),
                'stats'   => $this->buildStats($startDate, $endDate),
            ]);
        } catch (\Throwable $e) {
            Log::error('MonthlyReport error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando reporte'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Análisis IA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera un análisis IA del reporte mensual:
     * 1. Envía el JSON completo al servidor AI y pide 8 preguntas de análisis.
     * 2. Responde cada pregunta en el mismo hilo (la IA recuerda el contexto).
     * 3. Devuelve todas las respuestas agrupadas.
     */
    public function analyze(Request $request): JsonResponse
    {
        set_time_limit(0); // puede tomar varios minutos (9 llamadas al servidor AI)

        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        $aiUrl = env('AI_SERVER_URL', 'http://100.24.49.190:54321');
        $aiKey = env('AI_SERVER_KEY', 'finearom-ai-2025');

        Log::info("[AI-Analyze] Iniciando análisis período {$startDate} → {$endDate}");

        try {
            // 1. Construir reporte
            Log::info('[AI-Analyze] Construyendo reporte (ordenes + stats)...');
            $reportData = [
                'periodo' => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes' => $this->buildOrdenes($startDate, $endDate),
                'stats'   => $this->buildStats($startDate, $endDate),
            ];
            $ordersCount = count($reportData['ordenes']);
            Log::info("[AI-Analyze] Reporte construido: {$ordersCount} órdenes. Enviando al servidor AI...");

            // 2. Primer prompt: enviar reporte y obtener 8 preguntas de análisis
            $resp1 = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(310)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'        => 'gpt-4.1',
                    'messages'     => [['role' => 'user', 'content' => $this->buildInitialPrompt($reportData, $startDate, $endDate)]],
                    'extract_json' => true,
                ]);

            Log::info("[AI-Analyze] Prompt inicial → HTTP {$resp1->status()}");

            if (!$resp1->successful()) {
                Log::error('[AI-Analyze] Error en prompt inicial: ' . $resp1->body());
                throw new \RuntimeException("AI server error {$resp1->status()}: " . $resp1->body());
            }

            $threadId = $resp1->json('thread_id');
            $content1 = $resp1->json('choices.0.message.content', '');
            Log::info("[AI-Analyze] thread_id={$threadId} | Respuesta (primeros 300): " . substr($content1, 0, 300));

            $questions = $this->parseQuestions($content1);
            Log::info('[AI-Analyze] Preguntas parseadas: ' . count($questions));

            if (empty($questions)) {
                Log::error('[AI-Analyze] No se pudieron parsear preguntas. Contenido completo: ' . $content1);
                throw new \RuntimeException('El servidor AI no generó preguntas válidas. Respuesta: ' . substr($content1, 0, 300));
            }

            // 3. Responder cada pregunta en el mismo hilo
            $analisis = [];
            foreach ($questions as $i => $question) {
                $num = $i + 1;
                Log::info("[AI-Analyze] Pregunta {$num}/".count($questions).": " . substr($question, 0, 100));

                $respN = Http::withHeaders(['X-Api-Key' => $aiKey])
                    ->timeout(310)
                    ->post("{$aiUrl}/v1/chat/completions", [
                        'model'     => 'gpt-4.1',
                        'messages'  => [['role' => 'user', 'content' => $this->buildQuestionPrompt($question)]],
                        'thread_id' => $threadId,
                    ]);

                Log::info("[AI-Analyze] Respuesta {$num} → HTTP {$respN->status()}");

                if (!$respN->successful()) {
                    Log::warning("[AI-Analyze] Error en pregunta {$num} (thread={$threadId}): " . $respN->body());
                    $answer = 'No se pudo obtener respuesta para esta pregunta.';
                } else {
                    $answer = trim($respN->json('choices.0.message.content', ''));
                    Log::info("[AI-Analyze] Respuesta {$num} OK (" . strlen($answer) . " chars)");
                }

                $analisis[] = [
                    'pregunta'  => $question,
                    'respuesta' => $answer,
                ];
            }

            Log::info('[AI-Analyze] Análisis completado. ' . count($analisis) . ' respuestas generadas.');

            return response()->json([
                'success'     => true,
                'periodo'     => ['start_date' => $startDate, 'end_date' => $endDate],
                'thread_id'   => $threadId,
                'analisis'    => $analisis,
                'generado_en' => now()->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[AI-Analyze] ERROR: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Análisis IA — Streaming (Server-Sent Events)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Igual que analyze() pero devuelve los resultados en tiempo real via SSE.
     * El cliente recibe cada respuesta a medida que la IA la procesa.
     *
     * Formato de eventos:
     *   data: {"type":"start","total":8}
     *   data: {"type":"report_ready","ordenes_count":X}
     *   data: {"type":"questions_ready","preguntas":[...]}
     *   data: {"type":"answer","index":1,"total":8,"pregunta":"...","respuesta":"..."}
     *   data: {"type":"done","thread_id":"...","generado_en":"..."}
     *   data: {"type":"error","message":"..."}
     */
    public function stream(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();
        $aiUrl     = env('AI_SERVER_URL', 'http://localhost:54321');
        $aiKey     = env('AI_SERVER_KEY', 'finearom-ai-2025');

        return new StreamedResponse(function () use ($startDate, $endDate, $aiUrl, $aiKey) {
            set_time_limit(0);

            // Helper: enviar un evento SSE
            $send = function (array $data) {
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            };

            try {
                $send(['type' => 'start', 'total' => 8, 'periodo' => ['start_date' => $startDate, 'end_date' => $endDate]]);

                // 1. Construir reporte
                $reportData = [
                    'periodo' => ['start_date' => $startDate, 'end_date' => $endDate],
                    'ordenes' => $this->buildOrdenes($startDate, $endDate),
                    'stats'   => $this->buildStats($startDate, $endDate),
                ];
                $send(['type' => 'report_ready', 'ordenes_count' => count($reportData['ordenes'])]);

                // 2. Primer prompt: generar preguntas
                $send(['type' => 'status', 'message' => 'Analizando reporte con IA...']);
                $resp1 = Http::withHeaders(['X-Api-Key' => $aiKey])
                    ->timeout(310)
                    ->post("{$aiUrl}/v1/chat/completions", [
                        'model'        => 'gpt-4.1',
                        'messages'     => [['role' => 'user', 'content' => $this->buildInitialPrompt($reportData, $startDate, $endDate)]],
                        'extract_json' => true,
                    ]);

                if (!$resp1->successful()) {
                    $send(['type' => 'error', 'message' => "AI server error {$resp1->status()}"]);
                    return;
                }

                $threadId  = $resp1->json('thread_id');
                $content1  = $resp1->json('choices.0.message.content', '');
                $questions = $this->parseQuestions($content1);

                if (empty($questions)) {
                    $send(['type' => 'error', 'message' => 'No se generaron preguntas válidas. Respuesta: ' . substr($content1, 0, 200)]);
                    return;
                }

                $send(['type' => 'questions_ready', 'preguntas' => $questions]);

                // 3. Responder cada pregunta en el mismo hilo
                $analisis = [];
                foreach ($questions as $i => $question) {
                    $index = $i + 1;
                    $send(['type' => 'status', 'message' => "Respondiendo pregunta {$index} de " . count($questions) . '...']);

                    $respN = Http::withHeaders(['X-Api-Key' => $aiKey])
                        ->timeout(310)
                        ->post("{$aiUrl}/v1/chat/completions", [
                            'model'     => 'gpt-4.1',
                            'messages'  => [['role' => 'user', 'content' => $this->buildQuestionPrompt($question)]],
                            'thread_id' => $threadId,
                        ]);

                    $answer = $respN->successful()
                        ? trim($respN->json('choices.0.message.content', ''))
                        : 'No se pudo obtener respuesta.';

                    $item = ['pregunta' => $question, 'respuesta' => $answer];
                    $analisis[] = $item;

                    $send(['type' => 'answer', 'index' => $index, 'total' => count($questions)] + $item);
                }

                $send(['type' => 'done', 'thread_id' => $threadId, 'analisis' => $analisis, 'generado_en' => now()->toIso8601String()]);

            } catch (\Throwable $e) {
                Log::error('[AI-Stream] ERROR: ' . $e->getMessage());
                $send(['type' => 'error', 'message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no', // desactiva buffering en nginx
            'Connection'        => 'keep-alive',
        ]);
    }

    private function buildInitialPrompt(array $reportData, string $startDate, string $endDate): string
    {
        $json = json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Eres un analista comercial experto. Acabas de recibir el reporte mensual de Finearom
para el período {$startDate} al {$endDate}.

El reporte tiene dos secciones:
- "ordenes": órdenes despachadas con sus productos, clientes, valores USD/COP y TRM
- "stats": estadísticas agregadas (despachos, planeado, recaudos, cartera, TRM, stale_orders, top_productos)

Nota: los productos con "es_muestra: true" tienen precio \$0 (muestras comerciales).
No cuentan en los totales financieros pero sí representan actividad comercial.

REPORTE:
{$json}

Con base en estos datos, genera exactamente 8 preguntas de análisis concretas que investigaremos
una por una. Las preguntas deben estar respaldadas por los datos y cubrir estos ángulos:
1. Productos con mayor volumen despachado
2. Clientes con mayor valor generado
3. Cumplimiento: despachado real vs planeado
4. Alertas: órdenes estancadas o riesgos detectados
5. Recaudos y cobertura de cartera
6. Impacto del TRM en los totales COP
7. Muestras vs ventas comerciales reales
8. Una oportunidad o recomendación concreta para el siguiente mes

Responde ÚNICAMENTE con este JSON, sin texto adicional:
{"preguntas": ["...", "...", "...", "...", "...", "...", "...", "..."]}
PROMPT;
    }

    private function buildQuestionPrompt(string $question): string
    {
        return <<<PROMPT
Responde la siguiente pregunta usando los datos del reporte mensual que ya analizaste.
Sé específico: menciona nombres de productos, clientes, valores exactos y porcentajes cuando corresponda.
Máximo 150 palabras, respuesta directa en español.

Pregunta: {$question}
PROMPT;
    }

    private function parseQuestions(string $content): array
    {
        // Intentar parseo directo
        $decoded = json_decode($content, true);
        if (isset($decoded['preguntas']) && is_array($decoded['preguntas'])) {
            return array_values($decoded['preguntas']);
        }

        // Extraer JSON del texto si viene envuelto en markdown u otro texto
        if (preg_match('/\{[\s\S]*"preguntas"[\s\S]*\}/u', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (isset($decoded['preguntas']) && is_array($decoded['preguntas'])) {
                return array_values($decoded['preguntas']);
            }
        }

        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Órdenes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el array de órdenes con los productos (partials reales) embebidos.
     * Misma lógica que GoogleSheetsService::appendOrderRow.
     */
    private function buildOrdenes(string $startDate, string $endDate): array
    {
        $orders = PurchaseOrder::with(['client', 'branchOffice', 'project'])
            ->whereIn('status', ['completed', 'parcial_status'])
            ->whereBetween('dispatch_date', [$startDate, $endDate])
            ->orderBy('dispatch_date')
            ->get();

        return $orders->map(function (PurchaseOrder $order) {
            $orderTrm = (float) ($order->trm ?? 4000);

            // Partials reales — misma query que GoogleSheetsService
            $partials = DB::table('partials')
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

            $totalUsd    = 0.0;
            $totalCop    = 0.0;
            $transporter = '';
            $invoiceNum  = $order->invoice_number ?? '';
            $trackingNum = $order->tracking_number ?? '';
            $productos   = [];

            foreach ($partials as $partial) {
                $isSample = (int) $partial->muestra === 1;

                $effectivePrice = $isSample ? 0.0
                    : (float) (($partial->pivot_price > 0) ? $partial->pivot_price : ($partial->product_price ?? 0));

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

                $productos[] = [
                    'producto'      => $partial->product_name ?? '',
                    'cantidad'      => $qty,
                    'precio_usd'    => round($effectivePrice, 2),
                    'subtotal_usd'  => round($subtotalUsd, 2),
                    'subtotal_cop'  => round($subtotalCop, 0),
                    'trm'           => round($trm, 2),
                    'es_muestra'    => $isSample,
                    'new_win'       => (int) ($partial->new_win ?? 0) === 1,
                    'fecha_despacho'=> $partial->dispatch_date,
                    'factura'       => $partial->invoice_number ?? '',
                    'guia'          => $partial->tracking_number ?? '',
                    'transportadora'=> $partial->transporter ?? '',
                ];
            }

            // Fallback si no hay partials reales
            if ($partials->isEmpty()) {
                $order->load('products');
                foreach ($order->products as $product) {
                    $isSample   = (bool) ($product->pivot->muestra ?? false);
                    $pivotPrice = (float) ($product->pivot->price ?? 0);
                    $price      = $isSample ? 0.0 : ($pivotPrice > 0 ? $pivotPrice : (float) ($product->price ?? 0));
                    $qty        = (int) ($product->pivot->quantity ?? 0);
                    $subUsd     = $price * $qty;
                    $trm        = $orderTrm > 0 ? $orderTrm : 4000.0;
                    $subCop     = $subUsd * $trm;

                    $totalUsd += $subUsd;
                    $totalCop += $subCop;

                    $productos[] = [
                        'producto'      => $product->product_name ?? '',
                        'cantidad'      => $qty,
                        'precio_usd'    => round($price, 2),
                        'subtotal_usd'  => round($subUsd, 2),
                        'subtotal_cop'  => round($subCop, 0),
                        'trm'           => round($trm, 2),
                        'es_muestra'    => $isSample,
                        'new_win'       => (bool) ($product->pivot->new_win ?? false),
                        'fecha_despacho'=> null,
                        'factura'       => '',
                        'guia'          => '',
                        'transportadora'=> '',
                    ];
                }
            }

            $trm = $orderTrm > 0 ? $orderTrm : 4000.0;

            return [
                'fecha_despacho'   => $order->dispatch_date?->toDateString(),
                'consecutivo'      => $order->order_consecutive ?? '',
                'estado'           => $order->status,
                'cliente'          => $order->client?->client_name ?? '',
                'nit'              => $order->client?->nit ?? '',
                'sucursal'         => $order->branchOffice?->branch_name ?? '',
                'ciudad_entrega'   => $order->delivery_city ?? '',
                'total_usd'        => round($totalUsd, 2),
                'total_cop'        => round($totalCop, 0),
                'trm'              => round($trm, 2),
                'factura'          => $invoiceNum,
                'guia'             => $trackingNum,
                'transportadora'   => $transporter,
                'es_muestra'       => (bool) $order->is_muestra,
                'new_win'          => (bool) $order->is_new_win,
                'proyecto'         => $order->project?->name ?? '',
                'ejecutivo'        => $order->contact ?? '',
                'direccion_entrega'=> $order->delivery_address ?? '',
                'productos'        => $productos,
            ];
        })->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stats — misma lógica que DashboardController::getStats
    // ─────────────────────────────────────────────────────────────────────────

    private function buildStats(string $startDate, string $endDate): array
    {
        // Pre-computed daily snapshots
        $dailyStats = DB::table('order_statistics')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        // Totals from pre-computed stats
        $totalUsd      = $dailyStats->sum('commercial_dispatched_value_usd');
        $totalCop      = $dailyStats->sum('commercial_dispatched_value_cop');
        $totalProducts = $dailyStats->sum('commercial_products_dispatched');
        $totalOrders   = $dailyStats->sum('dispatched_orders_count');
        $daysCount     = $dailyStats->count();

        // Real-time planned stats
        $plannedUsd = 0;
        $plannedCop = 0;
        $plannedOrdersCount = 0;

        $orderProducts = DB::table('purchase_order_product')
            ->join('purchase_orders', 'purchase_order_product.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->leftJoin('partials', function ($join) {
                $join->on('partials.product_order_id', '=', 'purchase_order_product.id')
                    ->whereIn('partials.type', ['real', 'temporal']);
            })
            ->select(
                'purchase_orders.id as order_id',
                'purchase_orders.trm as order_trm',
                'purchase_order_product.quantity',
                'purchase_order_product.price as order_product_price',
                'purchase_order_product.muestra',
                'products.price as product_price',
                'partials.dispatch_date as partial_dispatch_date',
                'partials.type as partial_type',
                'partials.trm as partial_trm',
                'purchase_orders.order_creation_date',
            )
            ->get();

        $trmData = DB::table('trm_daily')
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('value', 'date')
            ->toArray();

        $ordersSet = [];
        foreach ($orderProducts->groupBy('order_id') as $orderId => $items) {
            $first      = $items->first();
            $isSample   = (int) $first->muestra === 1;
            $qty        = (int) $first->quantity;

            // Determine planned dispatch date
            $realPartial = $items->where('partial_type', 'real')->whereNotNull('partial_dispatch_date')->first();
            $tempPartial = $items->where('partial_type', 'temporal')->whereNotNull('partial_dispatch_date')->first();

            if ($realPartial) {
                $plannedDate = $realPartial->partial_dispatch_date;
                $partialTrm  = $realPartial->partial_trm;
            } elseif ($tempPartial) {
                $plannedDate = $tempPartial->partial_dispatch_date;
                $partialTrm  = null;
            } else {
                continue; // skip orders without planned date
            }

            if ($plannedDate < $startDate || $plannedDate > $endDate) {
                continue;
            }

            if (!$isSample) {
                $price = ($first->order_product_price > 0) ? $first->order_product_price : ($first->product_price ?? 0);
                $trm   = 4000.0;
                if ($realPartial && !empty($partialTrm) && $partialTrm > 3800) {
                    $trm = (float) $partialTrm;
                } elseif (isset($trmData[$plannedDate])) {
                    $trm = (float) $trmData[$plannedDate];
                } elseif (!empty($first->order_trm) && $first->order_trm > 3800) {
                    $trm = (float) $first->order_trm;
                }

                $plannedUsd += $price * $qty;
                $plannedCop += $price * $qty * $trm;
            }

            $ordersSet[$orderId] = true;
        }
        $plannedOrdersCount = count($ordersSet);

        // Orders created in the period (from daily stats)
        $totalOrdersCreated   = $dailyStats->sum('total_orders_created');
        $ordersPending        = $dailyStats->sum('orders_pending');
        $ordersProcessing     = $dailyStats->sum('orders_processing');
        $ordersParcialStatus  = $dailyStats->sum('orders_parcial_status');
        $ordersCompleted      = $dailyStats->sum('orders_completed');
        $ordersNewWin         = $dailyStats->sum('orders_new_win');
        $ordersCommercial     = $dailyStats->sum('orders_commercial');
        $ordersSample         = $dailyStats->sum('orders_sample');
        $ordersMixed          = $dailyStats->sum('orders_mixed');
        $ordersValueUsd       = $dailyStats->sum('total_orders_value_usd');
        $ordersValueCop       = $dailyStats->sum('total_orders_value_cop');

        // Recaudos del período
        $recaudos = DB::table('recaudos')
            ->whereBetween('fecha_recaudo', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereNotNull('fecha_recaudo')
            ->where('valor_cancelado', '>', 0)
            ->sum('valor_cancelado');

        $recaudosCount = DB::table('recaudos')
            ->whereBetween('fecha_recaudo', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereNotNull('fecha_recaudo')
            ->where('valor_cancelado', '>', 0)
            ->count();

        // Average TRM
        $validTrm = $dailyStats->where('average_trm', '>', 0);
        $avgTrm   = $validTrm->count() > 0 ? round($validTrm->avg('average_trm'), 2) : 4000;

        // Stale orders (processing > 7 days)
        $cutoff = Carbon::now()->subDays(7)->toDateString();
        $stale  = DB::table('purchase_orders')
            ->where('status', 'processing')
            ->where('order_creation_date', '<=', $cutoff)
            ->selectRaw('COUNT(*) as count, MIN(order_creation_date) as oldest_date')
            ->first();

        // Collection coverage
        $latestCarteraDate = DB::table('cartera')->max('fecha_cartera');
        $saldoCartera = 0;
        if ($latestCarteraDate) {
            $saldoCartera = DB::table('cartera')
                ->where('fecha_cartera', $latestCarteraDate)
                ->sum('saldo_contable');
        }
        $coverageRate = ($saldoCartera > 0) ? round(($recaudos / $saldoCartera) * 100, 2) : null;

        // TRM variation vs prior period
        $start    = Carbon::parse($startDate);
        $end      = Carbon::parse($endDate);
        $days     = $start->diffInDays($end) + 1;
        $prevEnd  = $start->copy()->subDay()->toDateString();
        $prevStart= Carbon::parse($prevEnd)->subDays($days - 1)->toDateString();
        $currentAvgTrm  = DB::table('trm_daily')->whereBetween('date', [$startDate, $endDate])->avg('value');
        $previousAvgTrm = DB::table('trm_daily')->whereBetween('date', [$prevStart, $prevEnd])->avg('value');
        $trmVariation    = ($currentAvgTrm && $previousAvgTrm && $previousAvgTrm > 0)
            ? round($currentAvgTrm - $previousAvgTrm, 2) : null;
        $trmVariationPct = ($trmVariation !== null && $previousAvgTrm > 0)
            ? round(($trmVariation / $previousAvgTrm) * 100, 2) : null;

        // Top 10 products
        $topProducts = DB::table('partials')
            ->join('purchase_order_product as pop', 'partials.product_order_id', '=', 'pop.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('partials.type', 'real')
            ->whereNotNull('partials.dispatch_date')
            ->whereBetween('partials.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', 0)
            ->groupBy('p.id', 'p.product_name', 'p.code')
            ->selectRaw('p.id, p.product_name as name, p.code as reference, SUM(partials.quantity) as total_units, COUNT(DISTINCT pop.purchase_order_id) as orders_count')
            ->orderByDesc('total_units')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'days_count' => $daysCount,
            ],
            'despachos' => [
                'value_usd'      => round($totalUsd, 2),
                'value_cop'      => round($totalCop, 0),
                'products_count' => (int) $totalProducts,
                'orders_count'   => (int) $totalOrders,
                'daily_avg_usd'  => $daysCount > 0 ? round($totalUsd / $daysCount, 2) : 0,
                'daily_avg_cop'  => $daysCount > 0 ? round($totalCop / $daysCount, 0) : 0,
            ],
            'planeado' => [
                'value_usd'    => round($plannedUsd, 2),
                'value_cop'    => round($plannedCop, 0),
                'orders_count' => $plannedOrdersCount,
            ],
            'ordenes_creadas' => [
                'total'          => (int) $totalOrdersCreated,
                'pending'        => (int) $ordersPending,
                'processing'     => (int) $ordersProcessing,
                'parcial_status' => (int) $ordersParcialStatus,
                'completed'      => (int) $ordersCompleted,
                'new_win'        => (int) $ordersNewWin,
                'commercial'     => (int) $ordersCommercial,
                'sample'         => (int) $ordersSample,
                'mixed'          => (int) $ordersMixed,
                'value_usd'      => round($ordersValueUsd, 2),
                'value_cop'      => round($ordersValueCop, 0),
            ],
            'recaudos' => [
                'total_cop' => round($recaudos, 0),
                'count'     => (int) $recaudosCount,
            ],
            'cartera' => [
                'saldo_total_cop'   => round($saldoCartera, 0),
                'coverage_rate_pct' => $coverageRate,
            ],
            'trm' => [
                'average'          => $avgTrm,
                'current_period'   => $currentAvgTrm ? round($currentAvgTrm, 2) : null,
                'previous_period'  => $previousAvgTrm ? round($previousAvgTrm, 2) : null,
                'variation'        => $trmVariation,
                'variation_pct'    => $trmVariationPct,
            ],
            'stale_orders' => [
                'count'       => (int) ($stale->count ?? 0),
                'oldest_date' => $stale->oldest_date ?? null,
            ],
            'top_productos' => $topProducts,
        ];
    }
}

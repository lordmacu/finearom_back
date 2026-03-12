<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'   => $this->buildStats($startDate, $endDate),
            ]);
        } catch (\Throwable $e) {
            Log::error('MonthlyReport error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando reporte'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chat IA sobre el reporte guardado
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inicia una nueva conversación con la IA usando el reporte guardado como contexto.
     * Devuelve el thread_id para continuar la conversación.
     */
    public function chatStart(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();
        $period    = ['start_date' => $startDate, 'end_date' => $endDate];

        $report = [
            'period'      => $period,
            'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
            'stats'       => $this->buildStats($startDate, $endDate),
        ];

        $now   = Carbon::now('America/Bogota');
        $today = $now->toDateString();

        // TRM de hoy (o la más reciente disponible) — para conversiones de cartera
        $trmHoy = DB::table('trm_daily')
            ->where('date', '<=', $today)
            ->orderByDesc('date')
            ->value('value');
        $trmHoyStr = $trmHoy ? number_format((float)$trmHoy, 2) : '4000.00';

        $prompt = "Eres un asistente de análisis comercial para Finearom. " .
                  "Te comparto el reporte mensual del período {$period['start_date']} al {$period['end_date']}. " .
                  "Responde todas las preguntas de forma clara y concisa en español. " .
                  "IMPORTANTE: Formatea SIEMPRE tus respuestas en HTML limpio (sin ```html ni backticks). " .
                  "Usa <p>, <strong>, <ul>, <li>, <table>, <tr>, <td>, <th> según corresponda. " .
                  "Para números destacados usa <strong>. Para listas usa <ul><li>. Para tablas usa <table> con clases inline básicas. " .
                  "Cuando el usuario haga una pregunta, respóndela directamente sin repetir el contexto.\n" .
                  "Fecha y hora actual (Colombia): {$now->format('Y-m-d H:i:s')} — úsala como referencia para calcular rangos relativos (hoy, esta semana, hace N días, etc.).\n" .
                  "TRM de hoy ({$today}): \${$trmHoyStr} COP/USD — úsala SOLO para convertir valores de cartera a USD si te lo piden. Las órdenes tienen su propia TRM individual y no deben usar esta.\n\n" .

                  "NOTAS CLAVE — lee antes de responder:\n" .
                  "- 'Total OC' = valor de las órdenes que tuvieron AL MENOS UN despacho real (dispatch_date) en el período — igual al dashboard\n" .
                  "- 'Despachado / Facturado' = dinero ya enviado al cliente (suma de partials reales del período). 'Pendiente' = Total OC − Facturado\n" .
                  "- 'Planeado' = OC con fecha de despacho programada dentro del período (subconjunto del total, NO igual al total)\n" .
                  "- Cumplimiento% puede superar el 100%: es normal porque los despachos del período pueden incluir OC creadas en meses anteriores\n" .
                  "- ESTADÍSTICAS POR EJECUTIVA es la fuente de verdad para preguntas por ejecutiva — NO sumar desde el detalle de órdenes\n" .
                  "- La columna '$/kg USD' en ESTADÍSTICAS POR EJECUTIVA es el precio promedio ponderado por kilo en USD — úsala directamente, NO calcules sumando órdenes del detalle\n" .
                  "- La columna 'Valor USD' en ESTADÍSTICAS POR EJECUTIVA es la fuente de verdad para el valor en USD de cada ejecutiva\n" .
                  "- 'Kilos despachados totales' (en RESUMEN FINANCIERO) = fuente de verdad para kilos totales del período\n" .
                  "- 'Líneas de producto despachadas' = número de ítems distintos, NO es kilos\n" .
                  "- En TOP PRODUCTOS, la columna 'Kilos despachados' = kilos reales (no unidades)\n" .
                  "- Productos marcados como (muestra) tienen precio $0 — no cuentan en totales financieros\n" .
                  "- En DETALLE DE ÓRDENES: estado pending = sin despachar, processing = en proceso, parcial_status = despacho parcial, completed = despachada totalmente\n" .
                  "- En DETALLE DE ÓRDENES, el Valor mostrado corresponde a lo despachado real (partials), no al valor total de la orden\n" .
                  "- En DETALLE DE ÓRDENES, el header de cada OC incluye la ejecutiva y los kilos totales (excluyendo muestras). Para verificar kilos de una ejecutiva, suma los kg del header de sus OCs — el resultado debe coincidir con la columna 'Kilos' de ESTADÍSTICAS POR EJECUTIVA\n" .
                  "- CARTERA y RECAUDOS son en PESOS COLOMBIANOS (COP). Para convertir a USD usar la TRM de hoy indicada arriba\n" .
                  "- NUNCA usar la TRM de hoy para convertir valores de órdenes — cada OC tiene su propia TRM\n" .
                  "- Cumplimiento / efectividad del período = (total despachado USD) / (total OC USD) × 100. Cuando alguien pregunte por el porcentaje de efectividad, cumplimiento o relación entre creadas y facturadas, SIEMPRE calcula y responde directamente con el número — NUNCA expliques la fórmula sin dar el resultado. Si se pregunta cuánto sería el cumplimiento si todo lo planeado se despacha, sumar el planeado + lo ya despachado y dividir entre el total OC\n" .
                  "- Cuando se pregunte por el producto [NEW WIN] con mayor o menor precio/kg, listar TODOS los productos marcados [NEW WIN] en DETALLE DE ÓRDENES sin excepción (busca todas las ocurrencias de [NEW WIN] en el detalle), compara sus precios unitarios y selecciona el extremo correcto\n" .
                  "- El 'valor de una OC' siempre es el campo 'Valor: X USD / Y COP' en el encabezado de la orden — NUNCA sumes solo las líneas de un producto específico dentro de ella (eso da el valor de ese producto, no de la orden). Ejemplo: si preguntan cuánto valen dos OCs que contienen BOUSCHET III, la respuesta es la suma de los campos Valor de esas dos OCs, no la suma de las líneas de BOUSCHET III\n" .
                  "- RESUMEN POR CLIENTE es la fuente de verdad para totales por cliente (OCs, USD, kilos) — NO sumes desde el detalle\n" .
                  "- TOP 10 PRODUCTOS PEDIDOS = los productos más demandados en las OCs del período (no despachados)\n" .
                  "- NEW WIN DEL PERÍODO = tabla de todos los productos marcados [NEW WIN], ordenados por precio/kg descendente\n\n" .

                  "REPORTE:\n" . ($md = $this->buildReportMarkdown($report)) . "\n\n" .

                  "ESQUEMA DE BASE DE DATOS:\n" . $this->getDbSchema() . "\n\n" .

                  "INSTRUCCIONES PARA QUERIES SQL:\n" .
                  "- SIEMPRE que el usuario pida una lista, ranking, tabla o detalle de datos (top N órdenes, clientes, productos, ejecutivas, despachos, cartera, etc.), genera una query SQL MariaDB lista para ejecutar — incluso si el dato está en el reporte precompilado. El usuario quiere verlo en tabla interactiva.\n" .
                  "- Solo responde sin query cuando la pregunta es un cálculo simple (ej: '¿cuánto es el cumplimiento?') o una explicación conceptual.\n" .
                  "- Presenta SIEMPRE la query así (HTML exacto, sin variaciones): <pre><code class=\"language-sql\">QUERY_SQL_AQUI</code></pre>\n" .
                  "- Antes del bloque SQL, escribe una línea breve diciendo qué muestra la consulta.\n" .
                  "- Las queries deben usar las tablas del ESQUEMA DE BASE DE DATOS proporcionado. Usa el período {$period['start_date']} a {$period['end_date']} como filtro cuando sea relevante.\n\n" .

                  "Confirma que recibiste el reporte con un mensaje breve de bienvenida (2-3 líneas) " .
                  "indicando el período, el total de órdenes creadas (valor USD), y cuánto se despachó/facturó realmente en USD.";

        Storage::disk('local')->put('monthly_report.md', $md);

        $periodLabel = Carbon::parse($startDate)->locale('es')->isoFormat('MMMM YYYY');

        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(60)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'    => 'gpt-4.1',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatStart: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error conectando con IA'], 500);
            }

            $threadId      = $resp->json('thread_id');
            $welcomeMsg    = trim($resp->json('choices.0.message.content', 'Listo, puedes hacerme preguntas sobre el reporte.'));
            $now           = now()->toDateTimeString();

            $session = ChatSession::create([
                'user_id'      => auth()->id(),
                'thread_id'    => $threadId,
                'period_label' => ucfirst($periodLabel),
                'period_start' => $startDate,
                'period_end'   => $endDate,
                'messages'     => [
                    ['role' => 'assistant', 'content' => $welcomeMsg, 'time' => $now],
                ],
            ]);

            return response()->json([
                'success'    => true,
                'session_id' => $session->id,
                'thread_id'  => $threadId,
                'message'    => $welcomeMsg,
                'period'     => $period,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatStart exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Inicia una nueva conversación con la IA usando un prompt ligero (sin reporte precompilado).
     * Envía solo el schema de DB, contexto de negocio e instrucciones de métricas.
     * Devuelve el thread_id para continuar la conversación.
     */
    public function chatStartV2(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();
        $period    = ['start_date' => $startDate, 'end_date' => $endDate];

        $now   = Carbon::now('America/Bogota');
        $today = $now->toDateString();

        // TRM de hoy (o la más reciente disponible)
        $trmHoy = DB::table('trm_daily')
            ->where('date', '<=', $today)
            ->orderByDesc('date')
            ->value('value');
        $trmHoyStr = $trmHoy ? number_format((float)$trmHoy, 2) : '4000.00';

        $prompt =
            "Eres un asistente de análisis comercial para Finearom, empresa colombiana que comercializa fragancias y materias primas para la industria cosmética.\n\n" .
            "PERÍODO ACTIVO: {$startDate} al {$endDate} (TRM de hoy: {$trmHoyStr} COP/USD).\n\n" .
            "ROLES EN FINEAROM Y QUÉ HACE CADA UNO:\n\n" .
            "FRANCY (Coordinadora de pedidos):\n" .
            "- Recibe los pedidos de las ejecutivas comerciales y los crea en el sistema como órdenes de compra (OC).\n" .
            "- Ingresa: cliente, productos, cantidades (kg), precio USD/kg pactado, TRM del día, fecha estimada de entrega.\n" .
            "- La OC queda en estado 'pending' (pendiente de revisión).\n" .
            "- También marca si la OC es 'is_muestra' (toda la orden es muestra sin costo) o si alguna línea es muestra.\n" .
            "- Marca 'is_new_win' si es un cliente o producto nuevo que la empresa ganó recientemente.\n\n" .
            "MARLON (Coordinador de logística / despachos):\n" .
            "- Revisa las órdenes creadas por Francy (estado 'pending').\n" .
            "- Agrega dos tipos de observaciones:\n" .
            "  * new_observation (observación al cliente): se envía por email al cliente en el hilo existente con Re:\n" .
            "  * internal_observation (nota interna de planta): solo para el equipo interno, NO se envía al cliente\n" .
            "- Establece la FECHA ESTIMADA DE DESPACHO para cada producto — estos quedan como 'partials' con type='temporal'.\n" .
            "  → En la tabla partials, type='temporal' = fecha planeada/estimada por Marlon\n" .
            "  → En la tabla partials, type='real' = despacho real confirmado por Alexa\n" .
            "- Cambia la OC a estado 'processing' (en preparación para despacho).\n" .
            "- Una OC en 'processing' con más de 7 días sin despachar se considera REZAGADA.\n\n" .
            "ALEXA (Coordinadora de facturación y despacho físico):\n" .
            "- Cuando la mercancía sale físicamente del almacén, Alexa registra el despacho REAL en el sistema.\n" .
            "- Crea registros en la tabla 'partials' con type='real' incluyendo:\n" .
            "  * dispatch_date: fecha real en que salió la mercancía\n" .
            "  * invoice_number: número de factura comercial\n" .
            "  * tracking_number: número de guía de la transportadora\n" .
            "  * transporter: empresa transportadora (ej: Coordinadora, Aldia, Servientrega)\n" .
            "  * trm: tasa de cambio COP/USD del día del despacho\n" .
            "  * quantity: kilos reales despachados\n" .
            "- También sube el PDF de la factura si está disponible.\n" .
            "- Si despacha TODO lo de la OC → la OC pasa a estado 'completed'.\n" .
            "- Si despacha PARTE de la OC → la OC pasa a estado 'parcial_status' (despacho parcial, queda pendiente el resto).\n" .
            "- Una misma OC puede tener múltiples partials type='real' a lo largo del tiempo (despachos en cuotas).\n" .
            "- Envía email automático al cliente y al equipo con la información del despacho.\n" .
            "- IMPORTANTE: los 'partials' type='real' son la fuente de verdad para lo que se FACTURÓ/DESPACHÓ realmente.\n\n" .
            "EJECUTIVAS COMERCIALES (Monica Castano, Juliana Pardo, Claudia Cueter, Maria Ortega, Camila Quintero, Daniela Aristizabal):\n" .
            "- Son las vendedoras que atienden a los clientes y negocian los pedidos.\n" .
            "- Cada cliente tiene asignada una ejecutiva (campo clients.executive, guardado como email).\n" .
            "- Las métricas por ejecutiva muestran su desempeño: cuánto vendieron, cuánto se despachó, kilos, new wins.\n\n" .
            "FLUJO COMPLETO DE UNA ORDEN:\n" .
            "1. Ejecutiva negocia con cliente → Francy crea OC (pending)\n" .
            "2. Marlon revisa, pone observaciones y fecha estimada → OC pasa a processing\n" .
            "3. Alexa despacha físicamente → crea partial(s) type='real' → OC pasa a parcial_status o completed\n\n" .
            "ESTADOS DE ÓRDENES Y TRANSICIONES:\n" .
            "- pending → Francy crea la OC. Puede ser cancelada por Francy o Marlon.\n" .
            "- processing → Marlon la mueve aquí al agregar observaciones y fechas estimadas de despacho. Puede ser cancelada por Marlon.\n" .
            "- parcial_status → Alexa despachó PARTE de la OC. Queda mercancía pendiente.\n" .
            "- completed → Alexa despachó TODO. Estado final de la OC.\n" .
            "- cancelled → Solo Francy o Marlon cancelan. Alexa NO cancela ni cambia a processing. Excluir de métricas.\n\n" .
            "MAPEO DE TÉRMINOS EN ESPAÑOL → VALORES REALES EN BD:\n" .
            "Cuando el usuario use estos términos, usa el valor exacto del campo status:\n" .
            "- 'pendiente', 'pendientes', 'creada' → 'pending'\n" .
            "- 'procesando', 'en proceso', 'procesado', 'en procesamiento' → 'processing'\n" .
            "- 'parcial', 'despacho parcial', 'parcialmente despachada' → 'parcial_status'\n" .
            "- 'completada', 'completa', 'entregada', 'despachada' → 'completed'\n" .
            "- 'cancelada', 'cancelado', 'anulada' → 'cancelled'\n\n" .
            "CUÁNDO NO FILTRAR POR FECHA DE CREACIÓN (regla crítica):\n" .
            "El filtro order_creation_date BETWEEN solo aplica cuando el usuario pregunta por órdenes CREADAS en un período.\n" .
            "Para estas preguntas NO uses BETWEEN sobre order_creation_date — las órdenes pueden ser de cualquier fecha:\n" .
            "- Órdenes 'estancadas', 'sin despacho', 'sin movimiento' → filtra por status + DATEDIFF(CURDATE(), po.order_creation_date) > X\n" .
            "- Kilos pendientes / órdenes en parcial_status → todas las parcial_status activas sin importar fecha de creación\n" .
            "- Pipeline (órdenes pending o processing sin completar) → sin filtro de fecha de creación\n" .
            "  → Ejemplo estancadas: WHERE po.status='processing' AND par.id IS NULL AND DATEDIFF(CURDATE(), po.order_creation_date) > 15\n" .
            "  → Ejemplo kilos pendientes: WHERE po.status='parcial_status' (sin BETWEEN)\n\n" .
            "CÓMO SABER QUÉ QUEDA PENDIENTE EN UNA OC (parcial_status):\n" .
            "- Kilos pedidos = SUM(pop.quantity) de purchase_order_product WHERE muestra=0\n" .
            "- Kilos despachados = SUM(par.quantity) de partials WHERE type='real' AND deleted_at IS NULL\n" .
            "- Kilos pendientes = kilos_pedidos - kilos_despachados (si > 0 hay mercancía sin despachar)\n" .
            "- Una OC pasa a 'completed' cuando Alexa despacha el resto y no quedan kilos pendientes\n\n" .
            "COMPORTAMIENTO CRÍTICO DE PARTIALS — SIEMPRE SOFT DELETE + REEMPLAZO:\n" .
            "- Cuando Alexa registra o actualiza un despacho: el sistema BORRA (soft delete) TODOS los partials type='real' existentes de esa OC y crea los nuevos desde cero.\n" .
            "  → Los partials anteriores quedan con deleted_at != NULL (son historial, no despachos vigentes)\n" .
            "  → Sin el filtro deleted_at IS NULL estarías contando despachos duplicados/obsoletos\n" .
            "- Cuando Marlon actualiza fechas estimadas: el sistema BORRA (soft delete) TODOS los partials type='temporal' y crea los nuevos.\n" .
            "  → Mismo patrón exacto que los reales\n" .
            "- REGLA ABSOLUTA: en CUALQUIER query sobre partials SIEMPRE incluir: AND par.deleted_at IS NULL\n\n" .
            "MÉTRICAS CLAVE DEL DASHBOARD (cómo calcularlas con SQL):\n" .
            "- \"Órdenes DESPACHADAS en el período\" = OCs con AL MENOS UN partial type='real' y dispatch_date en el período → usar EXISTS o JOIN por partials.dispatch_date\n" .
            "  → SIEMPRE incluir: par.type='real' AND par.dispatch_date BETWEEN fechas AND par.deleted_at IS NULL AND pop.muestra=0\n" .
            "  → NO usar order_creation_date para contar órdenes despachadas\n" .
            "- \"Órdenes CREADAS en el período\" = OCs donde order_creation_date BETWEEN fechas → NO usar partials, filtrar directo en purchase_orders\n" .
            "- \"Stats por ejecutiva (pedido vs despachado)\" — PATRÓN CORRECTO:\n" .
            "  → Empieza SIEMPRE desde partials con dispatch_date en el período\n" .
            "  → SUM(pop.quantity) = kilos pedidos en esas OCs (denominador)\n" .
            "  → SUM(par.quantity) = kilos despachados en el período (numerador)\n" .
            "  → Agrupa por c.executive — incluye TODAS las ejecutivas que tuvieron despachos (incluso si sus OCs se crearon en meses anteriores)\n" .
            "  → NUNCA filtres por order_creation_date para esta métrica — perderías ejecutivas cuyas OCs se crearon antes del período\n" .
            "  Ejemplo: SELECT ejecutiva, SUM(pop.quantity) kilos_pedidos, SUM(par.quantity) kilos_despachados, ROUND(SUM(par.quantity)/NULLIF(SUM(pop.quantity),0)*100,1) cumplimiento_pct FROM partials par JOIN purchase_order_product pop ON par.product_order_id=pop.id AND pop.muestra=0 JOIN products p ON pop.product_id=p.id JOIN purchase_orders po ON ar.order_id=po.id JOIN clients c ON po.client_id=c.id WHERE par.type='real' AND par.dispatch_date BETWEEN fechas AND par.deleted_at IS NULL GROUP BY c.executive ORDER BY kilos_despachados DESC\n" .
            "- \"Valor total OC\" = SUM(pop.quantity * precio) — usa pop.quantity (kilos PEDIDOS) para el denominador del cumplimiento\n" .
            "  ⚠ EXCEPCIÓN A LA REGLA GENERAL: aquí usa pop.quantity, NO par.quantity\n" .
            "  → SIEMPRE necesita: JOIN products p ON pop.product_id=p.id\n" .
            "- \"Despachado/Facturado\" = SUM(par.quantity * precio) — usa par.quantity (kilos REALES DESPACHADOS)\n" .
            "- \"Cumplimiento %\" = despachado_usd / total_oc_usd × 100. Calculado desde partials (NO desde order_creation_date)\n" .
            "- \"Fill Rate (tasa de llenado)\" = SUM(par.quantity) / SUM(pop.quantity) × 100 (en kilos). Mide qué porcentaje de lo pedido se despachó.\n" .
            "- \"Valor COP\" = valor_usd × COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)\n" .
            "  → Cascada TRM: partial.trm → po.trm → trm_daily (última fecha) → 4000\n" .
            "  → El +0 es necesario porque trm está guardado como string en MariaDB\n" .
            "- \"Kilos despachados\" = SUM(par.quantity) de partials reales del período (sin muestras)\n" .
            "- \"Pipeline / Pendiente por facturar\" = SUM de partials type='temporal' activos (deleted_at IS NULL). Valor aún no despachado pero programado.\n" .
            "- \"New Win (nivel OC)\" = COUNT DISTINCT po.id WHERE po.is_new_win=1 AND order_creation_date BETWEEN fechas\n" .
            "- \"New Win rate\" = new_win_orders / total_orders_creadas × 100\n" .
            "- \"New Win (nivel línea)\" = líneas donde pop.new_win=1, independiente del estado de la orden\n" .
            "- \"Muestra (nivel OC)\" = po.is_muestra=1 → toda la OC es muestra (excluir de totales)\n" .
            "- \"Muestra (nivel línea)\" = pop.muestra=1 → solo esa línea es muestra (excluir esa línea de totales)\n" .
            "  → En consultas de valor/kilos SIEMPRE filtrar: AND pop.muestra=0\n" .
            "- \"Órdenes rezagadas\" = po.status='processing' AND order_creation_date <= CURDATE() - 7 días → órdenes que Marlon aprobó pero Alexa aún no despacha\n" .
            "- \"Planeado del período\" = OCs cuya fecha de despacho estimada cae en el rango. Prioridad de fecha: 1) partial real dispatch_date, 2) partial temporal dispatch_date, 3) order_creation_date + 10 días hábiles como fallback. Este fallback incluye OCs sin ningún partial.\n" .
            "REGLA: si el usuario dice 'creadas', 'del período', 'de este mes' → usa order_creation_date en purchase_orders.\n" .
            "Si dice 'despachadas', 'facturadas', 'enviadas' → usa partials.dispatch_date con type='real' AND deleted_at IS NULL.\n\n" .
            "LEAD TIME Y TIPOS DE CLIENTE:\n" .
            "- client_type tiene DOS clasificaciones distintas:\n" .
            "  * Clasificación comercial: 'AA' (mayor prioridad) > 'A' > 'B' > 'C' (menor prioridad) — se usa para lead time\n" .
            "  * Clasificación portafolio (legado): 'pareto' (estratégico), 'balance', 'none'\n" .
            "- clients.lead_time (INT, días) = tiempo de entrega estándar del cliente (AA/A = 9 días, C = 12 días)\n" .
            "- Lead time real de una OC = DATEDIFF(fecha_primer_despacho_real, po.order_creation_date)\n" .
            "- On-time = el despacho real ocurrió en o antes de la fecha acordada de entrega\n" .
            "  → SIEMPRE usar pop.delivery_date (en purchase_order_product) — es la fecha acordada por línea, la más precisa para on-time\n" .
            "  → po.required_delivery_date es la fecha que pidió originalmente el cliente, suele ser anterior al despacho real, NO usar para on-time\n" .
            "  → ⚠ NUNCA uses pop.required_delivery_date — ese campo NO EXISTE en purchase_order_product\n" .
            "- Para análisis de lead time usar: JOIN con c.client_type IN ('AA','A','B','C')\n\n" .
            "CARTERA — FORMATO Y LÓGICA:\n" .
            "- saldo_contable y saldo_vencido son strings con PUNTO como separador decimal (ej: '26857379.12', '-256309.65'). Ya son decimales normales.\n" .
            "- Para operar numéricamente: CAST(saldo_contable AS DECIMAL(15,2)) — sin REPLACE, el punto es el decimal.\n" .
            "- NUNCA uses REPLACE para quitar puntos — destruyes el separador decimal y multiplicas el valor por 10.\n" .
            "- Para ordenar: ORDER BY CAST(saldo_contable AS DECIMAL(15,2)) DESC\n" .
            "- dias: POSITIVO = factura por vencer, NEGATIVO = factura VENCIDA hace |dias| días\n" .
            "- Facturas vencidas: WHERE dias < 0 — para días de mora usar ABS(ca.dias). NUNCA DATEDIFF(CURDATE(), fecha_cartera).\n" .
            "- ⚠ SIEMPRE filtrar: fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera) — sin esto traes todos los snapshots históricos y repites miles de filas.\n" .
            "- cartera.documento = número de factura → se cruza con recaudos.numero_factura para ver pagos\n" .
            "- Deuda neta real = saldo_contable MENOS lo pagado: MAX(saldo - SUM(recaudos.valor_cancelado), 0)\n" .
            "- catera_type: 'nacional' | 'internacional' (para filtrar cartera de exportación vs local)\n" .
            "- ⚠ CRÍTICO: cartera.vendedor y cartera.nombre_vendedor son el vendedor en SIIGO (ERP) y pueden NO coincidir con clients.executive.\n" .
            "  → Para filtrar cartera por ejecutiva: usa JOIN cartera ca ON ca.nit = clients.nit y filtra por clients.executive, NO por ca.vendedor.\n" .
            "- recaudos.nit es BIGINT (número sin texto). clients.nit y cartera.nit son VARCHAR. El JOIN funciona con conversión implícita en MariaDB.\n" .
            "- ⚠ CRÍTICO GROUP BY en cartera: la tabla cartera tiene UNA FILA POR FACTURA (no por cliente).\n" .
            "  → Si agrupas por nit/cliente, DEBES usar SUM(CAST(saldo_contable AS DECIMAL(15,2))) — nunca saldo_contable directo en SELECT.\n" .
            "  → Si muestras detalle por factura (sin GROUP BY), puedes usar CAST(saldo_contable AS DECIMAL(15,2)) directo.\n" .
            "  → En MariaDB ONLY_FULL_GROUP_BY, todo campo no-agregado en SELECT debe estar en GROUP BY.\n\n" .
            "REGLA CRÍTICA DE CANTIDADES:\n" .
            "Cuando JOIN partials → purchase_order_product, SIEMPRE usa par.quantity para calcular valor despachado.\n" .
            "pop.quantity es la cantidad PEDIDA, par.quantity es la cantidad REAL DESPACHADA.\n\n" .
            "EJECUTIVA: clients.executive puede ser email (ej: monica.castano@finearom.com).\n" .
            "Para mostrar como nombre: REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') AS ejecutiva\n" .
            "Siempre GROUP BY c.executive (no por el alias).\n\n" .
            "ESQUEMA DE BASE DE DATOS:\n" . $this->getDbSchema() . "\n\n" .
            "PREGUNTAS TÍPICAS POR ROL Y QUÉ QUERIES USAR:\n\n" .
            "Si el usuario pregunta por FRANCY (o 'órdenes creadas', 'ingresadas', 'pedidos nuevos'):\n" .
            "→ Filtrar por purchase_orders.order_creation_date BETWEEN fechas\n" .
            "→ Las OC pendientes de que Marlon revise: status = 'pending'\n" .
            "→ Cuánto ingresó Francy este mes: SUM por order_creation_date del período\n\n" .
            "Si el usuario pregunta por MARLON (o 'en proceso', 'pendientes de despacho', 'qué falta despachar', 'fechas estimadas'):\n" .
            "→ OC que Marlon tiene que revisar: status = 'pending'\n" .
            "→ OC que Marlon ya revisó (en preparación): status = 'processing'\n" .
            "→ OC rezagadas (Marlon revisó pero llevan >7 días sin despachar): status='processing' AND order_creation_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)\n" .
            "→ Fechas estimadas de despacho puestas por Marlon: partials WHERE type='temporal' AND deleted_at IS NULL\n\n" .
            "Si el usuario pregunta por ALEXA (o 'despachado', 'facturado', 'guías', 'facturas', 'enviado'):\n" .
            "→ Lo que despachó Alexa: partials WHERE type='real' AND deleted_at IS NULL AND dispatch_date BETWEEN fechas\n" .
            "→ Órdenes que Alexa aún debe completar: status = 'parcial_status'\n" .
            "→ Todas las guías del período: SELECT DISTINCT tracking_number, transporter FROM partials WHERE type='real'\n" .
            "→ Todas las facturas del período: SELECT DISTINCT invoice_number FROM partials WHERE type='real'\n\n" .
            "Si el usuario pregunta por CIUDAD DE ENTREGA o SUCURSAL:\n" .
            "→ Hacer LEFT JOIN branch_offices bo ON pop.branch_office_id = bo.id → usar bo.delivery_city, bo.name\n\n" .
            "INSTRUCCIONES:\n" .
            "- Responde SOLO en HTML limpio. NO uses LaTeX, NO uses markdown (no \\times, no **texto**, no backticks).\n" .
            "- NO generes tablas HTML con datos — el sistema ejecuta el SQL y muestra los resultados automáticamente.\n" .
            "- Para cualquier pregunta sobre datos (listas, rankings, totales, comparaciones): escribe un párrafo corto explicando qué hace la consulta + el bloque SQL.\n" .
            "- SQL siempre en: <pre><code class=\"language-sql\">QUERY</code></pre>\n" .
            "- Usa siempre las fechas del período activo en los filtros.\n" .
            "- ALIASES recomendados en SELECT para que la tabla muestre etiquetas legibles:\n" .
            "  client_name, ejecutiva, kilos, valor_usd, valor_cop, ocs, total_kilos, dispatched_kilos,\n" .
            "  fecha_despacho, fecha_creacion, numero_oc, saldo_cop, deuda_neta, vencido_cop, fill_rate_pct, pipeline_usd\n" .
            "- CRÍTICO GROUP BY (MariaDB ONLY_FULL_GROUP_BY): TODOS los campos no-agregados del SELECT deben estar en el GROUP BY.\n" .
            "  Si tienes c.client_name y c.executive en el SELECT → el GROUP BY debe incluir c.id, c.client_name, c.executive.\n" .
            "  Agrupa SIEMPRE por c.executive (no por el alias 'ejecutiva'). El alias va solo en el SELECT.\n" .
            "  NUNCA omitas columnas del SELECT en el GROUP BY aunque sean derivadas del id.\n" .
            "- PRESENTACIÓN: NUNCA incluyas columnas de id (id, client_id, product_id, etc.) en el SELECT.\n" .
            "  Cuando muestres nombre de cliente SIEMPRE incluye también el NIT del cliente en la misma query.\n" .
            "- Para preguntas conceptuales simples (¿qué es X?, ¿cómo funciona Y?) responde sin SQL.\n" .
            "- IMPORTANTE: después de cada bloque SQL, agrega siempre una línea breve con dos partes:\n" .
            "  1. Los campos que estás mostrando: '<p><small>Mostrando: <strong>campo1, campo2, campo3</strong>.</small></p>'\n" .
            "  2. Campos adicionales disponibles que el usuario puede pedir: '<p><small>Puedes pedirme que también muestre: campo4, campo5, campo6.</small></p>'\n" .
            "  CRÍTICO: usa SIEMPRE nombres en español legibles para el usuario, NUNCA nombres de columna de la BD. Ejemplos de traducción:\n" .
            "  order_consecutive → número de OC | dispatch_date → fecha de despacho | client_name → cliente | executive → ejecutiva\n" .
            "  status → estado | invoice_number → número de factura | tracking_number → número de guía | trm → TRM\n" .
            "  quantity → kilos | price → precio USD/kg | order_creation_date → fecha de creación | city → ciudad\n" .
            "  Ejemplo correcto: 'Mostrando: número de OC, cliente, fecha de despacho. Puedes pedirme que también muestre: ejecutiva, kilos, valor USD, número de factura, ciudad.'\n" .
            "- El mensaje de bienvenida debe ser: saludo breve, período activo, y 3-4 ejemplos de preguntas que puedes responder.";

        $periodLabel = Carbon::parse($startDate)->locale('es')->isoFormat('MMMM YYYY');

        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(60)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'    => 'gpt-4.1',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatStartV2: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error conectando con IA'], 500);
            }

            $threadId   = $resp->json('thread_id');
            $welcomeMsg = trim($resp->json('choices.0.message.content', 'Listo, puedes hacerme preguntas sobre el período.'));
            $now        = now()->toDateTimeString();

            $session = ChatSession::create([
                'user_id'      => auth()->id(),
                'thread_id'    => $threadId,
                'period_label' => ucfirst($periodLabel),
                'period_start' => $startDate,
                'period_end'   => $endDate,
                'messages'     => [
                    ['role' => 'assistant', 'content' => $welcomeMsg, 'time' => $now],
                ],
            ]);

            return response()->json([
                'success'    => true,
                'session_id' => $session->id,
                'thread_id'  => $threadId,
                'message'    => $welcomeMsg,
                'period'     => $period,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatStartV2 exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Envía un mensaje del usuario al hilo existente y devuelve la respuesta de la IA.
     */
    public function chatMessage(Request $request): JsonResponse
    {
        $request->validate([
            'thread_id'  => 'required|string',
            'message'    => 'required|string|max:2000',
            'session_id' => 'nullable|integer',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        // Cargar sesión para obtener el período real
        $session = null;
        $periodStart = 'INICIO';
        $periodEnd   = 'FIN';
        if ($request->session_id) {
            $session = ChatSession::where('id', $request->session_id)
                ->where('user_id', auth()->id())
                ->first();
            if ($session) {
                $periodStart = $session->period_start?->toDateString() ?? 'INICIO';
                $periodEnd   = $session->period_end?->toDateString()   ?? 'FIN';
            }
        }

        // Recordatorio compacto para que la IA no pierda contexto entre turnos
        $schemaHint = "Recuerda: eres el asistente de análisis de Finearom. " .
            "Base de datos: MariaDB 11.4 — soporta CTEs (WITH), window functions (SUM/ROW_NUMBER OVER), y FIELD() para ordenamiento personalizado. Úsalos cuando aporten claridad (ej: Pareto con pct acumulado, rankings). " .
            "Período activo de esta sesión: {$periodStart} al {$periodEnd}. " .
            "REGLA DE FECHAS: usa SIEMPRE {$periodStart} y {$periodEnd} como filtro por defecto en todas las queries. " .
            "EXCEPCIÓN 1: si el usuario menciona fechas distintas en su mensaje, esas fechas SOBREESCRIBEN el período activo. " .
            "EXCEPCIÓN 2: para preguntas sobre órdenes 'estancadas', 'sin despacho hace X días', 'sin movimiento' — NO uses BETWEEN sobre order_creation_date; usa solo DATEDIFF(CURDATE(), po.order_creation_date) > X sin filtro de período. " .
            "Base de datos MariaDB. Tablas principales: " .
            "purchase_orders (id, client_id, order_consecutive, status, order_creation_date, dispatch_date, trm, is_new_win, is_muestra), " .
            "clients (id, client_name, nit, executive, client_type, city), " .
            "purchase_order_product (id, purchase_order_id, product_id, quantity kg pedidos, price USD/kg nullable, new_win, muestra, branch_office_id FK→branch_offices), " .
            "branch_offices (id, client_id, name, nit, delivery_address, delivery_city — sucursal de entrega por línea), " .
            "products (id, code, product_name, price USD/kg — precio catálogo fallback), " .
            "partials (id, order_id, product_order_id, quantity kg despachados, type 'temporal'=Marlon estimó|'real'=Alexa despachó, dispatch_date, trm, invoice_number, tracking_number, transporter, deleted_at SOFT DELETE), " .
            "cartera (nit, nombre_empresa, saldo_contable STRING formato colombiano '1.234.567,89', saldo_vencido STRING, fecha_cartera), " .
            "recaudos (nit, cliente, fecha_recaudo, valor_cancelado COP), " .
            "trm_daily (date, value COP/USD). " .
            "REGLA de filtro por período: si la pregunta es sobre órdenes DESPACHADAS/FACTURADAS → usa partials: par.type='real' AND par.dispatch_date BETWEEN '{$periodStart}' AND '{$periodEnd}' AND par.deleted_at IS NULL AND pop.muestra=0. " .
            "Si la pregunta es sobre órdenes CREADAS → usa purchase_orders directo: po.order_creation_date BETWEEN '{$periodStart}' AND '{$periodEnd}' (sin JOIN a partials). " .
            "Para mostrar ejecutiva como nombre (no email): GROUP BY c.executive, SELECT REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') AS ejecutiva. " .
            "CRÍTICO de cantidades: usa SIEMPRE par.quantity (kilos despachados), NO pop.quantity (kilos pedidos). " .
            "CRÍTICO de precio: pop.price puede ser NULL en órdenes antiguas. SIEMPRE usa COALESCE(NULLIF(pop.price,0), p.price, 0) y haz JOIN products p ON pop.product_id=p.id. " .
            "Precio efectivo = COALESCE(NULLIF(pop.price,0), p.price, 0). " .
            "Valor USD despachado = par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0). " .
            "CRÍTICO de TRM (cascada 4 niveles): COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000). El +0 convierte string a número. " .
            "Valor COP despachado = par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) * COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000). " .
            "EXCEPCIÓN pop.quantity vs par.quantity: para 'Total OC' (denominador de cumplimiento) usa pop.quantity (pedido). Solo usa par.quantity para lo despachado. " .
            "CRÍTICO de cartera: saldo_contable ya es decimal normal con punto ('26857379.12'). Usar: CAST(saldo_contable AS DECIMAL(15,2)). NUNCA uses REPLACE para quitar puntos — destruyes el decimal. " .
            "CARTERA POR EJECUTIVA: NO filtres por cartera.vendedor — usa JOIN cartera ca ON ca.nit = clients.nit y filtra por clients.executive. " .
            "CARTERA GROUP BY: cartera tiene UNA FILA POR FACTURA. Si agrupas por nit/cliente → usa SUM(CAST(saldo_contable AS DECIMAL(15,2))). Si listas facturas individuales → sin GROUP BY, usa CAST directo. NUNCA mezcles saldo_contable sin agregar en un SELECT con GROUP BY. " .
            "ON-TIME: usa SIEMPRE pop.delivery_date (en purchase_order_product). NUNCA po.required_delivery_date para on-time — es la fecha original del cliente, siempre está en el pasado y da 0%. pop.required_delivery_date NO EXISTE. " .
            "FILL RATE: siempre partir desde partials (dispatch_date en período), NO desde order_creation_date. " .
            "Query correcta: FROM partials par JOIN purchase_order_product pop ON par.product_order_id=pop.id AND pop.muestra=0 WHERE par.type='real' AND par.deleted_at IS NULL AND par.dispatch_date BETWEEN X AND Y. " .
            "Fill rate = SUM(par.quantity)/SUM(pop.quantity)*100. " .
            "TENDENCIAS DIARIAS: usa tabla order_statistics (date, commercial_dispatched_value_usd, dispatched_orders_count, total_orders_created, dispatch_fulfillment_rate_usd, pending_dispatch_value_usd, extended_stats JSON) — es un snapshot pre-calculado por día, más eficiente que agregar partials. " .
            "CRÍTICO GROUP BY (MariaDB ONLY_FULL_GROUP_BY): TODOS los campos no-agregados del SELECT deben estar en el GROUP BY. " .
            "Si el SELECT tiene c.client_name, c.executive → el GROUP BY DEBE tener c.id, c.client_name, c.executive. " .
            "Puedes agrupar por c.executive (no por el alias 'ejecutiva') y mostrar el alias solo en el SELECT. " .
            "NUNCA omitas columnas del SELECT en el GROUP BY aunque parezcan redundantes con el id. " .
            "⚠ PROHIBIDO: subquery correlacionada en SELECT cuando hay GROUP BY — SIEMPRE da error ONLY_FULL_GROUP_BY en MariaDB. " .
            "PATRÓN OBLIGATORIO para primer despacho por OC: usa JOIN con subquery pre-calculada: " .
            "JOIN (SELECT order_id, MIN(dispatch_date) AS first_dispatch FROM partials WHERE type='real' AND deleted_at IS NULL GROUP BY order_id) fd ON fd.order_id = po.id — luego usa fd.first_dispatch en el SELECT/HAVING. " .
            "NUNCA escribas (SELECT MIN(par2.dispatch_date) FROM partials par2 WHERE par2.order_id = po.id ...) dentro del SELECT o HAVING cuando tienes GROUP BY. " .
            "PRESENTACIÓN: NUNCA incluyas columnas de id (id, client_id, product_id, purchase_order_id, etc.) en el SELECT — son números internos irrelevantes para el usuario. " .
            "Cuando muestres nombre de cliente (c.client_name o ca.nombre_empresa) SIEMPRE incluye también c.nit o ca.nit en la misma query. " .
            "CRÍTICO de formato: NO generes tablas HTML — el sistema renderiza los resultados del SQL automáticamente. Escribe solo un párrafo corto explicativo + el bloque SQL. NO uses LaTeX ni markdown (no \\times, no **texto**). " .
            "Para cualquier lista/ranking/tabla SIEMPRE incluye SQL en: <pre><code class=\"language-sql\">SQL</code></pre>. " .
            "ALIASES: usa estos nombres en SELECT AS para que la tabla se muestre correctamente: client_name, ejecutiva, kilos, valor_usd, valor_cop, ocs, total_kilos, dispatched_kilos, fecha_despacho, fecha_creacion, numero_oc, saldo_cop, deuda_neta. " .
            "ESTADOS (mapeo español → BD): 'pendiente/pendientes/creada' → 'pending'; 'procesando/en proceso/procesado/en procesamiento' → 'processing'; 'parcial/despacho parcial' → 'parcial_status'; 'completada/completa/entregada/despachada' → 'completed'; 'cancelada/anulada' → 'cancelled'. " .
            "Después del SQL agrega siempre en español legible (NUNCA nombres de columna BD): '<p><small>Mostrando: X, Y, Z. Puedes pedirme que también muestre: ...</small></p>'.\n\nMensaje del usuario: ";

        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(120)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'     => 'gpt-4.1',
                    'messages'  => [['role' => 'user', 'content' => $schemaHint . $request->message]],
                    'thread_id' => $request->thread_id,
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatMessage: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error en IA'], 500);
            }

            $aiMessage = trim($resp->json('choices.0.message.content', ''));
            $now       = now()->toDateTimeString();

            // Persistir mensajes en la sesión si se proveyó session_id
            if ($session) {
                $messages   = $session->messages ?? [];
                $messages[] = ['role' => 'user',      'content' => $request->message, 'time' => $now];
                $messages[] = ['role' => 'assistant', 'content' => $aiMessage,         'time' => $now];
                $session->update(['messages' => $messages]);
            }

            return response()->json([
                'success'   => true,
                'message'   => $aiMessage,
                'thread_id' => $resp->json('thread_id'),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatMessage exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Lista las últimas sesiones de chat del usuario autenticado.
     */
    public function chatSessions(): JsonResponse
    {
        $sessions = ChatSession::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'period_label', 'period_start', 'period_end', 'thread_id', 'created_at', 'messages'])
            ->map(fn($s) => [
                'id'            => $s->id,
                'period_label'  => $s->period_label,
                'period_start'  => $s->period_start?->toDateString(),
                'period_end'    => $s->period_end?->toDateString(),
                'thread_id'     => $s->thread_id,
                'created_at'    => $s->created_at->toDateTimeString(),
                'message_count' => count($s->messages ?? []),
            ]);

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    /**
     * Carga los mensajes de una sesión específica.
     */
    public function chatSessionMessages(ChatSession $session): JsonResponse
    {
        abort_if($session->user_id !== auth()->id(), 403);

        return response()->json([
            'success'  => true,
            'session'  => [
                'id'           => $session->id,
                'period_label' => $session->period_label,
                'period_start' => $session->period_start?->toDateString(),
                'period_end'   => $session->period_end?->toDateString(),
                'thread_id'    => $session->thread_id,
                'messages'     => $session->messages ?? [],
            ],
        ]);
    }

    /**
     * Elimina una sesión de chat del usuario autenticado.
     */
    public function chatSessionDelete(ChatSession $session): JsonResponse
    {
        abort_if($session->user_id !== auth()->id(), 403);
        $session->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Ejecuta una query SELECT enviada por el frontend (generada por la IA).
     * Solo permite SELECT — bloquea cualquier otra operación DML/DDL.
     */
    public function runQuery(Request $request): JsonResponse
    {
        $request->validate(['sql' => 'required|string|max:5000']);

        $sql = trim($request->input('sql'));

        // Solo SELECT permitido
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            return response()->json(['success' => false, 'message' => 'Solo se permiten consultas SELECT'], 422);
        }

        // Bloquear patrones peligrosos
        $forbidden = ['DROP ', 'DELETE ', 'UPDATE ', 'INSERT ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'EXEC(', 'EXECUTE(', 'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE'];
        foreach ($forbidden as $pattern) {
            if (stripos($sql, $pattern) !== false) {
                return response()->json(['success' => false, 'message' => 'Query no permitida'], 422);
            }
        }

        try {
            $results = DB::select($sql);

            if (empty($results)) {
                return response()->json(['success' => true, 'columns' => [], 'rows' => [], 'count' => 0]);
            }

            $columns = array_keys((array) $results[0]);
            $rows    = array_map(fn($row) => array_values((array) $row), $results);

            return response()->json([
                'success' => true,
                'columns' => $columns,
                'rows'    => $rows,
                'count'   => count($rows),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Generar y guardar reporte en disco
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera el reporte mensual y lo guarda en storage/app/monthly_report.md
     * Siempre sobreescribe el archivo anterior.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        try {
            $report = [
                'period'      => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'       => $this->buildStats($startDate, $endDate),
            ];

            $md = $this->buildReportMarkdown($report);
            Storage::disk('local')->put('monthly_report.md', $md);

            Log::info("[MonthlyReport] Reporte generado y guardado ({$startDate} → {$endDate})");

            return response()->json([
                'success'       => true,
                'message'       => 'Reporte generado correctamente',
                'generated_at'  => now()->toIso8601String(),
                'period'        => $report['period'],
                'ordenes_count' => count($report['ordenes_mes']),
            ]);
        } catch (\Throwable $e) {
            Log::error('MonthlyReport generate error: ' . $e->getMessage());
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

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        Log::info("[AI-Analyze] Iniciando análisis período {$startDate} → {$endDate}");

        try {
            // 1. Construir reporte
            Log::info('[AI-Analyze] Construyendo reporte (ordenes + stats)...');
            $reportData = [
                'periodo' => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'   => $this->buildStats($startDate, $endDate),
            ];
            $ordersCount = count($reportData['ordenes_mes']);
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
        $aiUrl     = config('custom.ai_server_url');
        $aiKey     = config('custom.ai_server_key');

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
                    'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                    'stats'   => $this->buildStats($startDate, $endDate),
                ];
                $send(['type' => 'report_ready', 'ordenes_count' => count($reportData['ordenes_mes'])]);

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
     * Construye el array de órdenes CREADAS en el período (todos los estados).
     * Las órdenes despachadas tienen status = completed | parcial_status.
     * Las no despachadas tienen status = pending | processing.
     */
    private function buildOrdenes(string $startDate, string $endDate): array
    {
        $orders = PurchaseOrder::with(['client', 'branchOffice', 'project'])
            ->whereBetween('order_creation_date', [$startDate, $endDate])
            ->orderBy('order_creation_date')
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
                'ejecutivo'        => $this->normalizeExecutiveName($order->client?->executive ?? ''),
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
        // Pre-computed daily snapshots (solo para métricas secundarias)
        $dailyStats = DB::table('order_statistics')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        $daysCount = $dailyStats->count();

        // Despachos reales del período — misma lógica que DashboardController::calculateExecutiveStats()
        $dispatchRow = DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'pt.dispatch_date', '=', 'td.date')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.dispatch_date')
            ->whereBetween('pt.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COUNT(DISTINCT po.id) as orders_count,
                SUM((CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity *
                    COALESCE(NULLIF(pt.trm,0), NULLIF(po.trm,0), NULLIF(td.value,0), 4000)
                ) as value_cop
            ")
            ->first();

        $totalUsd    = (float) ($dispatchRow->value_usd   ?? 0);
        $totalCop    = (float) ($dispatchRow->value_cop   ?? 0);
        $totalOrders = (int)   ($dispatchRow->orders_count ?? 0);
        $totalProducts = $dailyStats->sum('commercial_products_dispatched');

        // Total kilos dispatched (real partials in period, excluding samples)
        $totalKilosDispatched = (float) DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.dispatch_date')
            ->whereBetween('pt.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', 0)
            ->sum('pt.quantity');

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

        // OCs con al menos un despacho real en el período — misma lógica que DashboardController::calculateExecutiveStats()
        $ordersCreatedQuery = DB::table('purchase_orders as po')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                    ->from('partials as pt')
                    ->whereColumn('pt.product_order_id', 'pop.id')
                    ->where('pt.type', 'real')
                    ->whereNotNull('pt.dispatch_date')
                    ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
            })
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COUNT(DISTINCT po.id) as total_orders,
                SUM(CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END * pop.quantity) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pop.quantity *
                    COALESCE(NULLIF(po.trm, 0), NULLIF(td.value, 0), 4000)
                ) as value_cop
            ")
            ->first();

        $totalOrdersCreated  = (int)   ($ordersCreatedQuery->total_orders ?? 0);
        $ordersValueUsd      = (float) ($ordersCreatedQuery->value_usd    ?? 0);
        $ordersValueCop      = (float) ($ordersCreatedQuery->value_cop    ?? 0);

        // Status counts — de las OCs con despacho real en el período
        $statusCounts = DB::table('purchase_orders')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                    ->from('partials as pt')
                    ->join('purchase_order_product as pop2', 'pt.product_order_id', '=', 'pop2.id')
                    ->whereColumn('pop2.purchase_order_id', 'purchase_orders.id')
                    ->where('pt.type', 'real')
                    ->whereNotNull('pt.dispatch_date')
                    ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
            })
            ->selectRaw("
                SUM(CASE WHEN status = 'pending'        THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing'     THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'parcial_status' THEN 1 ELSE 0 END) as parcial_status,
                SUM(CASE WHEN status = 'completed'      THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN is_new_win = 1            THEN 1 ELSE 0 END) as new_win
            ")
            ->first();

        $ordersPending        = (int) ($statusCounts->pending        ?? 0);
        $ordersProcessing     = (int) ($statusCounts->processing     ?? 0);
        $ordersParcialStatus  = (int) ($statusCounts->parcial_status ?? 0);
        $ordersCompleted      = (int) ($statusCounts->completed      ?? 0);
        $ordersNewWin         = (int) ($statusCounts->new_win        ?? 0);
        $ordersCommercial     = $dailyStats->sum('orders_commercial');
        $ordersSample         = $dailyStats->sum('orders_sample');
        $ordersMixed          = $dailyStats->sum('orders_mixed');

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
        // Cartera — snapshot más reciente (misma lógica que CarteraQuery)
        $today = Carbon::now('America/Bogota')->toDateString();
        $latestCarteraDate = (string) (DB::table('cartera')->max('fecha_cartera') ?? $today);

        $carteraBase = DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate);

        $saldoCartera = (float) DB::table('cartera')
            ->where('fecha_cartera', $latestCarteraDate)
            ->sum(DB::raw("CAST(saldo_contable AS DECIMAL(20,2))"));

        // Deuda neta (saldo - recaudos totales por factura, mínimo 0)
        $netDebt = (float) DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate)
            ->groupBy('car.documento', 'car.saldo_contable')
            ->selectRaw("GREATEST(CAST(car.saldo_contable AS DECIMAL(20,2)) - COALESCE(SUM(r.valor_cancelado), 0), 0) as net_debt")
            ->pluck('net_debt')
            ->sum();

        // Deuda vencida (vence < hoy)
        $overdueDebt = (float) DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate)
            ->where(function ($q) use ($today) {
                $q->where('car.vence', '<', $today)
                  ->orWhere(function ($n) { $n->whereNotNull('car.dias')->where('car.dias', '<', 0); });
            })
            ->groupBy('car.documento', 'car.saldo_contable')
            ->selectRaw("GREATEST(CAST(car.saldo_contable AS DECIMAL(20,2)) - COALESCE(SUM(r.valor_cancelado), 0), 0) as net_debt")
            ->pluck('net_debt')
            ->sum();

        $coverageRate = ($netDebt > 0) ? round(($recaudos / $netDebt) * 100, 2) : null;

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
                'value_usd'              => round($totalUsd, 2),
                'value_cop'              => round($totalCop, 0),
                'total_kilos_dispatched' => round($totalKilosDispatched, 2),
                'products_count'         => (int) $totalProducts,
                'orders_count'           => (int) $totalOrders,
                'daily_avg_usd'          => $daysCount > 0 ? round($totalUsd / $daysCount, 2) : 0,
                'daily_avg_cop'          => $daysCount > 0 ? round($totalCop / $daysCount, 0) : 0,
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
            'pendiente' => [
                'value_usd' => round(max(0, $ordersValueUsd - $totalUsd), 2),
                'value_cop' => round(max(0, $ordersValueCop - $totalCop), 0),
            ],
            'recaudos' => [
                'total_cop' => round($recaudos, 0),
                'count'     => (int) $recaudosCount,
            ],
            'cartera' => [
                'snapshot_date'    => $latestCarteraDate,
                'saldo_bruto_cop'  => round($saldoCartera, 0),   // saldo contable bruto del snapshot (COP)
                'deuda_neta_cop'   => round($netDebt, 0),        // saldo - recaudos históricos (COP)
                'deuda_vencida_cop'=> round($overdueDebt, 0),    // porción vencida de la deuda neta (COP)
                'coverage_rate_pct'=> $coverageRate,             // % cubierto por recaudos del período
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
            'top_productos'  => $topProducts,
            'executive_stats' => $this->buildExecutiveStats($startDate, $endDate),
        ];
    }

    /**
     * Estadísticas por ejecutiva: OC creadas + despachos reales (misma lógica que DashboardController)
     */
    private function buildExecutiveStats(string $startDate, string $endDate): array
    {
        // Normalize: if executive is an email, extract readable name
        $normalizeExec = function (string $exec): string {
            if (!str_contains($exec, '@')) return $exec;
            $prefix = explode('@', $exec)[0];
            return ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
        };

        // Misma lógica que DashboardController::calculateExecutiveStats():
        // OCs que tienen al menos un despacho real con dispatch_date en el período
        $ordersQuery = DB::table('purchase_orders as po')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                    ->from('partials as pt')
                    ->whereColumn('pt.product_order_id', 'pop.id')
                    ->where('pt.type', 'real')
                    ->whereNotNull('pt.dispatch_date')
                    ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
            })
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COALESCE(NULLIF(c.executive, ''), 'Sin ejecutiva') as executive,
                COUNT(DISTINCT po.id) as total_orders,
                SUM(pop.quantity) as total_kilos,
                SUM(
                    CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END * pop.quantity
                ) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pop.quantity *
                    COALESCE(NULLIF(po.trm, 0), NULLIF(td.value, 0), 4000)
                ) as value_cop
            ")
            ->groupBy('c.executive')
            ->get();

        $dispatchQuery = DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'pt.dispatch_date', '=', 'td.date')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.dispatch_date')
            ->whereBetween('pt.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COALESCE(NULLIF(c.executive, ''), 'Sin ejecutiva') as executive,
                COUNT(DISTINCT po.id) as dispatched_orders,
                SUM(pt.quantity) as dispatched_kilos,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity
                ) as dispatched_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity *
                    COALESCE(NULLIF(pt.trm, 0), NULLIF(po.trm, 0), NULLIF(td.value, 0), 4000)
                ) as dispatched_cop
            ")
            ->groupBy('c.executive')
            ->get();

        // Merge orders by normalized executive name
        $merged = [];
        foreach ($ordersQuery as $row) {
            $exec = $normalizeExec($row->executive);
            if (!isset($merged[$exec])) {
                $merged[$exec] = ['executive' => $exec, 'total_orders' => 0, 'total_kilos' => 0.0, 'value_usd' => 0.0, 'value_cop' => 0.0];
            }
            $merged[$exec]['total_orders'] += (int)   $row->total_orders;
            $merged[$exec]['total_kilos']  += (float) $row->total_kilos;
            $merged[$exec]['value_usd']    += (float) $row->value_usd;
            $merged[$exec]['value_cop']    += (float) $row->value_cop;
        }

        // Merge dispatches by normalized executive name
        $dispatchMerged = [];
        foreach ($dispatchQuery as $row) {
            $exec = $normalizeExec($row->executive);
            if (!isset($dispatchMerged[$exec])) {
                $dispatchMerged[$exec] = ['dispatched_orders' => 0, 'dispatched_kilos' => 0.0, 'dispatched_usd' => 0.0, 'dispatched_cop' => 0.0];
            }
            $dispatchMerged[$exec]['dispatched_orders'] += (int)   $row->dispatched_orders;
            $dispatchMerged[$exec]['dispatched_kilos']  += (float) $row->dispatched_kilos;
            $dispatchMerged[$exec]['dispatched_usd']    += (float) ($row->dispatched_usd ?? 0);
            $dispatchMerged[$exec]['dispatched_cop']    += (float) $row->dispatched_cop;
        }

        $totalValueCop = array_sum(array_column($merged, 'value_cop'));

        $result = [];
        foreach ($merged as $exec => $row) {
            $dispatch  = $dispatchMerged[$exec] ?? null;
            $valueCop  = $row['value_cop'];
            $valueUsd  = $row['value_usd'];
            $kilos     = $row['total_kilos'];
            $dispCop    = $dispatch ? $dispatch['dispatched_cop']    : 0;
            $dispUsd    = $dispatch ? $dispatch['dispatched_usd']    : 0;
            $dispKilos  = $dispatch ? $dispatch['dispatched_kilos']  : 0;
            $dispOrders = $dispatch ? $dispatch['dispatched_orders'] : 0;

            $result[] = [
                'executive'            => $exec,
                'total_orders'         => $row['total_orders'],
                'value_usd'            => round($valueUsd, 2),
                'value_cop'            => round($valueCop, 0),
                'total_kilos'          => round($kilos, 2),
                'dispatched_orders'    => $dispOrders,
                'dispatched_usd'       => round($dispUsd, 2),
                'dispatched_cop'       => round($dispCop, 0),
                'dispatched_kilos'     => round($dispKilos, 2),
                'participation_pct'    => $totalValueCop > 0 ? round($valueCop / $totalValueCop * 100, 1) : 0,
                'compliance_usd_pct'   => $valueUsd > 0 ? round($dispUsd   / $valueUsd  * 100, 1) : 0,
                'compliance_cop_pct'   => $valueCop  > 0 ? round($dispCop   / $valueCop  * 100, 1) : 0,
                'compliance_kilos_pct' => $kilos     > 0 ? round($dispKilos / $kilos     * 100, 1) : 0,
            ];
        }

        usort($result, fn ($a, $b) => $b['value_cop'] <=> $a['value_cop']);

        return $result;
    }

    /**
     * Convierte el reporte JSON a Markdown estructurado para el prompt de IA.
     * Más legible que JSON crudo, sin perder ningún dato.
     */
    private function normalizeExecutiveName(string $executive): string
    {
        if (!str_contains($executive, '@')) {
            return $executive; // ya es un nombre
        }
        $local = explode('@', $executive)[0]; // "monica.castano"
        $parts = explode('.', $local);
        return implode(' ', array_map('ucfirst', $parts));
    }

    private function buildReportMarkdown(array $report): string
    {
        $stats  = $report['stats']      ?? [];
        $period = $report['period']     ?? [];
        $orders = $report['ordenes_mes'] ?? [];

        $desp  = $stats['despachos']       ?? [];
        $plan  = $stats['planeado']        ?? [];
        $oc    = $stats['ordenes_creadas'] ?? [];
        $pend  = $stats['pendiente']       ?? [];
        $rec   = $stats['recaudos']        ?? [];
        $cart  = $stats['cartera']         ?? [];
        $trm   = $stats['trm']             ?? [];
        $stale = $stats['stale_orders']    ?? [];
        $tops  = $stats['top_productos']   ?? [];
        $execs = $stats['executive_stats'] ?? [];

        $n = fn($v, $d = 0) => number_format((float) $v, $d, '.', ',');

        $md  = "# REPORTE MENSUAL FINEAROM\n";
        $md .= "**Período:** {$period['start_date']} al {$period['end_date']}\n\n";

        // ── RESUMEN FINANCIERO ──────────────────────────────────────────────
        $md .= "## RESUMEN FINANCIERO\n";
        $md .= "| Métrica | USD | COP |\n|---|---:|---:|\n";
        $md .= "| Total OC (con despacho en período) | \${$n($oc['value_usd'] ?? 0, 2)} | \${$n($oc['value_cop'] ?? 0)} |\n";
        $md .= "| Despachado / Facturado | \${$n($desp['value_usd'] ?? 0, 2)} | \${$n($desp['value_cop'] ?? 0)} |\n";
        $md .= "| Pendiente por facturar | \${$n($pend['value_usd'] ?? 0, 2)} | \${$n($pend['value_cop'] ?? 0)} |\n";
        $md .= "| Planeado en el período | \${$n($plan['value_usd'] ?? 0, 2)} | \${$n($plan['value_cop'] ?? 0)} |\n\n";

        $md .= "**Kilos despachados totales:** {$n($desp['total_kilos_dispatched'] ?? 0, 2)} kg";
        $md .= " | **Líneas de producto despachadas (≠ kilos):** " . ($desp['products_count'] ?? 0);
        $md .= " | **Órdenes despachadas:** " . ($desp['orders_count'] ?? 0) . "\n\n";

        // ── ESTADO DE ÓRDENES ───────────────────────────────────────────────
        $md .= "## ÓRDENES CREADAS EN EL PERÍODO\n";
        $md .= "| Estado | Cantidad |\n|---|---:|\n";
        $md .= "| **Total** | " . ($oc['total'] ?? 0) . " |\n";
        $md .= "| Pendientes (pending) | " . ($oc['pending'] ?? 0) . " |\n";
        $md .= "| En proceso (processing) | " . ($oc['processing'] ?? 0) . " |\n";
        $md .= "| Despacho parcial (parcial_status) | " . ($oc['parcial_status'] ?? 0) . " |\n";
        $md .= "| Completadas (completed) | " . ($oc['completed'] ?? 0) . " |\n";
        $md .= "| New win | " . ($oc['new_win'] ?? 0) . " |\n\n";

        // ── CARTERA ─────────────────────────────────────────────────────────
        $md .= "## CARTERA (snapshot: " . ($cart['snapshot_date'] ?? 'N/A') . ") — todos los valores en COP\n";
        $md .= "| Campo | Valor COP |\n|---|---:|\n";
        $md .= "| Saldo bruto contable | \${$n($cart['saldo_bruto_cop'] ?? 0)} |\n";
        $md .= "| Deuda neta (saldo − recaudos históricos) | \${$n($cart['deuda_neta_cop'] ?? 0)} |\n";
        $md .= "| Deuda vencida | \${$n($cart['deuda_vencida_cop'] ?? 0)} |\n";
        $cov = $cart['coverage_rate_pct'] !== null ? ($cart['coverage_rate_pct'] . '%') : 'N/A';
        $md .= "| Cobertura por recaudos del período | {$cov} |\n\n";

        // ── RECAUDOS ────────────────────────────────────────────────────────
        $md .= "## RECAUDOS DEL PERÍODO\n";
        $md .= "- **Total recaudado:** \${$n($rec['total_cop'] ?? 0)} COP\n";
        $md .= "- **Número de recaudos:** " . ($rec['count'] ?? 0) . "\n\n";

        // ── TRM ─────────────────────────────────────────────────────────────
        $trmValues = array_filter(array_column($orders, 'trm'), fn($v) => (float)$v > 0);
        $trmMin    = $trmValues ? $n(min($trmValues), 2) : null;
        $trmMax    = $trmValues ? $n(max($trmValues), 2) : null;
        $md .= "## TRM\n";
        $md .= "- **TRM promedio del período:** \${$n($trm['average'] ?? 0, 2)} COP/USD\n";
        if ($trmMin && $trmMax) {
            $md .= "- **TRM mínima / máxima:** \${$trmMin} / \${$trmMax} COP/USD\n";
        }
        if (($trm['variation'] ?? null) !== null) {
            $sign = ($trm['variation'] >= 0) ? '+' : '';
            $md .= "- **Variación vs período anterior:** {$sign}{$n($trm['variation'], 2)} ({$sign}{$trm['variation_pct']}%)\n";
        }
        $md .= "\n";

        // ── ÓRDENES ESTANCADAS ──────────────────────────────────────────────
        if (($stale['count'] ?? 0) > 0) {
            $md .= "## ÓRDENES ESTANCADAS (en proceso > 7 días)\n";
            $md .= "- **Cantidad:** " . $stale['count'] . "\n";
            $md .= "- **Más antigua creada el:** " . ($stale['oldest_date'] ?? 'N/A') . "\n\n";
        }

        // ── TOP PRODUCTOS ───────────────────────────────────────────────────
        $md .= "## TOP 10 PRODUCTOS DESPACHADOS (por kilos)\n";
        $md .= "| # | Producto | Referencia | Kilos despachados | # Órdenes |\n|---|---|---|---:|---:|\n";
        foreach ($tops as $i => $p) {
            $p    = (array) $p;
            $md  .= "| " . ($i + 1) . " | " . ($p['name'] ?? '') . " | " . ($p['reference'] ?? '') .
                    " | " . $n($p['total_units'] ?? 0, 2) . " | " . ($p['orders_count'] ?? 0) . " |\n";
        }
        $md .= "\n";

        // ── ESTADÍSTICAS POR EJECUTIVA ──────────────────────────────────────
        $md .= "## ESTADÍSTICAS POR EJECUTIVA\n";
        $md .= "| Ejecutiva | OC | Valor USD | Valor COP | $/kg USD | Kilos | Part.% | OC desp. | Desp. USD | Desp. COP | Desp. kg | Cump.% USD |\n";
        $md .= "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|\n";
        foreach ($execs as $e) {
            $kilos    = (float) $e['total_kilos'];
            $valueUsd = (float) $e['value_usd'];
            $dispUsd  = (float) ($e['dispatched_usd'] ?? 0);
            $pxkg     = $kilos > 0 ? $n($valueUsd / $kilos, 2) : '—';
            $md .= "| {$e['executive']}";
            $md .= " | {$e['total_orders']}";
            $md .= " | \${$n($valueUsd, 2)}";
            $md .= " | \${$n($e['value_cop'])}";
            $md .= " | \${$pxkg}";
            $md .= " | {$n($kilos, 2)}";
            $md .= " | {$e['participation_pct']}%";
            $md .= " | {$e['dispatched_orders']}";
            $md .= " | \${$n($dispUsd, 2)}";
            $md .= " | \${$n($e['dispatched_cop'])}";
            $md .= " | {$n($e['dispatched_kilos'], 2)}";
            $cumpl = $e['compliance_usd_pct'] ?? $e['compliance_cop_pct'];
            $md .= " | {$cumpl}%";
            $md .= " |\n";
        }
        $md .= "\n";

        // ── RESUMEN POR CLIENTE ─────────────────────────────────────────────
        $clientMap = [];
        foreach ($orders as $o) {
            $ckey = ($o['cliente'] ?? '') . '||' . ($o['nit'] ?? '');
            if (!isset($clientMap[$ckey])) {
                $clientMap[$ckey] = [
                    'cliente'    => $o['cliente']   ?? '',
                    'nit'        => $o['nit']        ?? '',
                    'ejecutiva'  => $o['ejecutivo']  ?? '',
                    'ocs'        => 0,
                    'value_usd'  => 0.0,
                    'value_cop'  => 0.0,
                    'kilos'      => 0.0,
                ];
            }
            $clientMap[$ckey]['ocs']++;
            $clientMap[$ckey]['value_usd'] += (float)($o['total_usd'] ?? 0);
            $clientMap[$ckey]['value_cop'] += (float)($o['total_cop'] ?? 0);
            foreach ($o['productos'] ?? [] as $p) {
                if (empty($p['es_muestra'])) {
                    $clientMap[$ckey]['kilos'] += (float)($p['cantidad'] ?? 0);
                }
            }
        }
        usort($clientMap, fn($a, $b) => $b['value_usd'] <=> $a['value_usd']);
        $md .= "## RESUMEN POR CLIENTE\n";
        $md .= "| Cliente | NIT | OCs | Valor USD | Valor COP | Kilos | Ejecutiva |\n";
        $md .= "|---|---|---:|---:|---:|---:|---|\n";
        foreach ($clientMap as $c) {
            $md .= "| {$c['cliente']} | {$c['nit']} | {$c['ocs']} | \${$n($c['value_usd'], 2)} | \${$n($c['value_cop'])} | {$n($c['kilos'], 2)} | {$c['ejecutiva']} |\n";
        }
        $md .= "\n";

        // ── TOP 10 PRODUCTOS PEDIDOS ─────────────────────────────────────────
        $pedidosMap = [];
        foreach ($orders as $o) {
            $cons = $o['consecutivo'] ?? '';
            foreach ($o['productos'] ?? [] as $p) {
                if (!empty($p['es_muestra'])) continue;
                $pname = $p['producto'] ?? '';
                if (!isset($pedidosMap[$pname])) {
                    $pedidosMap[$pname] = ['producto' => $pname, 'kilos' => 0.0, 'ocs' => []];
                }
                $pedidosMap[$pname]['kilos'] += (float)($p['cantidad'] ?? 0);
                $pedidosMap[$pname]['ocs'][$cons] = true;
            }
        }
        usort($pedidosMap, fn($a, $b) => $b['kilos'] <=> $a['kilos']);
        $top10ped = array_slice($pedidosMap, 0, 10);
        $md .= "## TOP 10 PRODUCTOS PEDIDOS (por kilos en OCs del período)\n";
        $md .= "| # | Producto | Kilos pedidos | # OCs |\n|---|---|---:|---:|\n";
        foreach ($top10ped as $i => $p) {
            $md .= "| " . ($i + 1) . " | {$p['producto']} | {$n($p['kilos'], 2)} | " . count($p['ocs']) . " |\n";
        }
        $md .= "\n";

        // ── NEW WIN CONSOLIDADO ──────────────────────────────────────────────
        $newWinRows = [];
        foreach ($orders as $o) {
            $cons    = $o['consecutivo'] ?? '';
            $exec    = $o['ejecutivo']   ?? '';
            $cliente = $o['cliente']     ?? '';
            foreach ($o['productos'] ?? [] as $p) {
                if (!empty($p['new_win'])) {
                    $newWinRows[] = [
                        'oc'          => $cons,
                        'producto'    => $p['producto']    ?? '',
                        'kilos'       => (float)($p['cantidad']   ?? 0),
                        'precio_usd'  => (float)($p['precio_usd'] ?? 0),
                        'ejecutiva'   => $exec,
                        'cliente'     => $cliente,
                    ];
                }
            }
        }
        usort($newWinRows, fn($a, $b) => $b['precio_usd'] <=> $a['precio_usd']);
        if (!empty($newWinRows)) {
            $md .= "## NEW WIN DEL PERÍODO\n";
            $md .= "| OC | Producto | Kilos | $/kg USD | Ejecutiva | Cliente |\n";
            $md .= "|---|---|---:|---:|---|---|\n";
            foreach ($newWinRows as $nw) {
                $md .= "| {$nw['oc']} | {$nw['producto']} | {$n($nw['kilos'], 2)} | \${$n($nw['precio_usd'], 2)} | {$nw['ejecutiva']} | {$nw['cliente']} |\n";
            }
            $md .= "\n";
        }

        // ── DETALLE DE ÓRDENES DEL MES ──────────────────────────────────────
        $md .= "## DETALLE DE ÓRDENES DEL MES\n\n";
        foreach ($orders as $o) {
            $cons    = $o['consecutivo']    ?? '';
            $estado  = $o['estado']         ?? '';
            $cliente   = $o['cliente']        ?? '';
            $nit       = $o['nit']            ?? '';
            $sucursal  = $o['sucursal']       ?? '';
            $exec      = $o['ejecutivo']      ?? '';
            $ciudad    = $o['ciudad_entrega'] ?? '';
            $fecha   = $o['fecha_despacho'] ?? 'Sin despachar';
            $usd     = $n($o['total_usd']   ?? 0, 2);
            $cop     = $n($o['total_cop']   ?? 0);
            $trm2    = $n($o['trm']         ?? 0, 2);
            $factura = $o['factura']        ?? '';
            $guia    = $o['guia']           ?? '';
            $trans   = $o['transportadora'] ?? '';
            $tags    = [];
            if (!empty($o['es_muestra'])) $tags[] = 'MUESTRA';
            if (!empty($o['new_win']))     $tags[] = 'NEW WIN';
            $tagStr  = $tags ? ' [' . implode(', ', $tags) . ']' : '';

            // Kilos totales de la OC (excluyendo muestras)
            $totalKgOc = 0;
            foreach (($o['productos'] ?? []) as $p) {
                if (empty($p['es_muestra'])) {
                    $totalKgOc += (float)($p['cantidad'] ?? 0);
                }
            }

            $totalUsdOc = (float)($o['total_usd'] ?? 0);
            $usdHeader  = $totalUsdOc > 0 ? " | \${$n($totalUsdOc, 2)} USD" : '';
            $md .= "### OC {$cons}{$tagStr} — {$estado} | Ejecutiva: {$exec} | {$n($totalKgOc, 2)} kg{$usdHeader}\n";
            $nitStr      = $nit      ? " | **NIT:** {$nit}"           : '';
            $sucursalStr = $sucursal ? " | **Sucursal:** {$sucursal}" : '';
            $md .= "- **Cliente:** {$cliente}{$nitStr}{$sucursalStr} | **Ciudad:** {$ciudad}\n";
            $md .= "- **Despacho:** {$fecha}";
            if ($factura) $md .= " | **Factura:** {$factura} | **Guía:** {$guia} | **Trans.:** {$trans}";
            $md .= "\n";
            if (($o['total_usd'] ?? 0) > 0) {
                $md .= "- **Valor:** \${$usd} USD / \${$cop} COP (TRM: {$trm2})\n";
            }

            $prods = $o['productos'] ?? [];
            if (!empty($prods)) {
                $md .= "- **Productos:**\n";
                foreach ($prods as $p) {
                    $pname  = $p['producto']     ?? '';
                    $qty    = $n($p['cantidad']   ?? 0, 2);
                    $price  = $n($p['precio_usd'] ?? 0, 2);
                    $ptot   = $n($p['subtotal_usd'] ?? 0, 2);
                    $muStr  = !empty($p['es_muestra']) ? ' (muestra)' : '';
                    $nwStr  = !empty($p['new_win'])    ? ' [NEW WIN]' : '';
                    $md    .= "  - {$pname}{$muStr}{$nwStr}: {$qty} kg @ \${$price} = \${$ptot} USD\n";
                }
            }
            $md .= "\n";
        }

        return $md;
    }

    private function getDbSchema(): string
    {
        return <<<'SCHEMA'
## TABLAS RELEVANTES

### clients — Clientes
- id PK, client_name, nit (UNIQUE), executive (EMAIL de la ejecutiva — ej: monica.castano@finearom.com), status ('active'|'inactive'), city, operation_type ('nacional'|'extranjero')
- client_type: dos clasificaciones coexisten:
  * Clasificación comercial/prioridad: 'AA' (mayor prioridad), 'A', 'B', 'C' (menor prioridad) — se usa para lead time y análisis de cumplimiento
  * Clasificación de portafolio (legacy): 'pareto' (cliente estratégico), 'balance', 'none'
  * En el dashboard de lead time SOLO se consideran AA/A/B/C
- lead_time (INT días) — tiempo de entrega estándar del cliente. Ej: AA/A = 9 días, C = 12 días
- required_delivery_date en purchase_orders = fecha solicitada de entrega (usada para medir cumplimiento)
- payment_method (1=Contado, 2=Crédito), payment_day (día del mes para pago), credit_term (días de crédito)
- purchase_frequency (frecuencia de compra), estimated_monthly_quantity (kilos mensuales estimados)
- portfolio_contact_email, dispatch_confirmation_email, purchasing_contact_email, logistics_contact_email
- iva, retefuente, reteiva, ica (tasas impositivas del cliente)

### branch_offices — Sucursales de clientes
- id PK, client_id FK→clients.id, name, nit, delivery_address, delivery_city
- Cada cliente puede tener múltiples sucursales con distintas direcciones y ciudades de entrega

### purchase_orders — Órdenes de compra
- id PK, client_id FK→clients.id, order_consecutive (ej: '2258-4500302325'), status ('pending'|'processing'|'completed'|'cancelled'|'parcial_status'), order_creation_date (date), dispatch_date (date — fecha estimada puesta por Marlon), required_delivery_date (date — fecha solicitada por el cliente), trm, is_new_win (0/1), is_muestra (0/1), observations, created_at
- pending = Francy creó, esperando Marlon | processing = Marlon revisó, en preparación | parcial_status = Alexa despachó parcialmente | completed = Alexa despachó todo
- LEAD TIME por OC = DATEDIFF(fecha_real_despacho, order_creation_date). Comparar con clients.lead_time para saber si fue en tiempo.
- ON TIME = dispatch real <= required_delivery_date (campo en purchase_orders, NO en purchase_order_product)
- NOTA: la ejecutiva se obtiene JOIN clients → clients.executive

### purchase_order_product — Líneas de producto en OCs
- id PK, purchase_order_id FK→purchase_orders.id, product_id FK→products.id, quantity (kg pedidos), price (USD/kg negociado — puede ser NULL en OCs antiguas), new_win (0/1), muestra (0/1), delivery_date (date — fecha de entrega por línea), branch_office_id FK→branch_offices.id (sucursal de entrega), status
- ⚠ required_delivery_date NO existe en purchase_order_product — está en purchase_orders. Para on-time usar po.required_delivery_date o pop.delivery_date
- cierre_cartera (DATETIME) — fecha esperada de cierre/pago de esta línea
- Para precio real usar siempre: COALESCE(NULLIF(pop.price,0), p.price, 0)

### products — Productos
- id PK, code, product_name, price (USD/kg actual — precio catálogo fallback), client_id FK→clients.id, categories (JSON array de categorías — ej: ["Floral","Amaderado"])
- CRÍTICO: los productos son POR CLIENTE (client_id). La misma fragancia puede tener precios distintos por cliente.
- El precio en products es el precio catálogo vigente. Para precio negociado en una OC específica → usar pop.price (puede ser NULL en OCs antiguas)
- Para filtrar por categoría: JSON_SEARCH(LOWER(p.categories), 'one', '%floral%') IS NOT NULL
- Para listar categorías: JSON_UNQUOTE(JSON_EXTRACT(p.categories, '$[0]'))

### product_price_history — Historial de precios
- id PK, product_id FK→products.id, price (DECIMAL USD/kg), effective_date (fecha desde la que aplica, normalmente 1 enero), created_by FK→users.id
- Útil para preguntas como "¿cuál era el precio en enero?" o "¿cómo ha cambiado el precio de X?"

### partials — Despachos
- id PK, order_id FK→purchase_orders.id, product_order_id FK→purchase_order_product.id, product_id FK→products.id, quantity (kg), type ('temporal'|'real'), dispatch_date (date), trm, invoice_number, pdf_invoice, tracking_number, transporter, deleted_at (soft delete)
- CRÍTICO: para análisis de despachos del período SIEMPRE filtrar: type = 'real' AND dispatch_date BETWEEN ... AND deleted_at IS NULL AND pop.muestra = 0

### cartera — Cartera (COP, importada de sistema contable SIIGO)
- id PK, nit, nombre_empresa, fecha_cartera (date del snapshot — agrupa todas las filas del mismo import)
- documento (VARCHAR) — número de factura. Se une con recaudos.numero_factura para ver pagos de esa factura
- fecha (DATE) — fecha de emisión de la factura
- vence (DATE) — fecha de vencimiento de la factura
- dias (INT) — días de cartera: POSITIVO = factura AÚN no vencida (días restantes), NEGATIVO = factura VENCIDA hace N días
  → Para días de mora usar: ABS(ca.dias) WHERE ca.dias < 0. NUNCA usar DATEDIFF(CURDATE(), fecha_cartera) — eso es la antigüedad del snapshot, no los días de mora.
- saldo_contable (STRING COP) — saldo total de la factura
- saldo_vencido (STRING COP) — porción ya vencida del saldo
- vendedor, nombre_vendedor — vendedor en SIIGO (puede diferir de clients.executive)
- catera_type (VARCHAR) — 'nacional' | 'internacional' | NULL (null = nacional por defecto)
- ciudad, cuenta, descripcion_cuenta
- CRÍTICO: saldo_contable y saldo_vencido son strings con PUNTO como separador decimal (ej: '26857379.12', '-256309.65'). Ya son decimales normales.
  → Para operar: CAST(saldo_contable AS DECIMAL(15,2)) — sin REPLACE, el punto ya es el decimal
  → NUNCA uses REPLACE para quitar puntos — destruyes el separador decimal y multiplicas el valor por 10
- CRÍTICO: NUNCA filtrar con fecha hardcodeada. SIEMPRE: fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
- CRÍTICO: para deuda NETA real = saldo_contable MENOS lo pagado en recaudos: MAX(saldo - SUM(recaudos), 0)
- Para ordenar: ORDER BY CAST(saldo_contable AS DECIMAL(15,2)) DESC
- Facturas VENCIDAS: WHERE dias < 0 OR vence < CURDATE()
- Facturas POR VENCER: WHERE dias >= 0 AND vence >= CURDATE()

### recaudos — Pagos recibidos (COP)
- id PK, nit (BIGINT), cliente, fecha_recaudo (DATETIME), numero_factura (VARCHAR — join con cartera.documento), numero_recibo, valor_cancelado (DECIMAL COP), fecha_vencimiento (DATETIME — vencimiento de la factura al momento del pago), dias (días de mora al pagar)
- Para ver cuánto se pagó de una factura específica: JOIN cartera ON recaudos.numero_factura = cartera.documento
- Para deuda neta de un cliente: SUM(cartera.saldo_contable) - SUM(recaudos.valor_cancelado) por nit

### trm_daily — TRM diaria
- id PK, date (date), value (decimal COP/USD), is_weekend (0/1), is_holiday (0/1)

### order_statistics — Snapshot diario pre-calculado (MUY EFICIENTE para tendencias)
- date (UNIQUE), total_orders_created, orders_pending, orders_processing, orders_completed, orders_parcial_status, orders_new_win
- total_orders_value_usd, total_orders_value_cop — valor total de OCs creadas ese día
- dispatched_orders_count, commercial_dispatched_value_usd, commercial_dispatched_value_cop — despachos del día
- orders_commercial, orders_sample, orders_mixed — clasificación de OCs
- pending_dispatch_value_usd/cop — valor pendiente por despachar
- planned_dispatch_value_usd/cop, planned_orders_count — planeado del día
- dispatch_fulfillment_rate_usd — fill rate en USD (%)
- avg_days_order_to_first_dispatch — lead time promedio del día
- unique_clients_with_orders, unique_clients_with_dispatches
- extended_stats (JSON) — métricas avanzadas del día
- NOTA: es un snapshot pre-calculado, más eficiente para preguntas de tendencias/evolución diaria

## RELACIONES
- clients (1) → purchase_orders (N) vía client_id
- purchase_orders (1) → purchase_order_product (N) vía purchase_order_id
- purchase_order_product (N) → products (1) vía product_id
- purchase_order_product (N) → branch_offices (1) vía branch_office_id (sucursal de entrega por línea)
- purchase_orders (1) → partials (N) vía order_id
- partials (N) → purchase_order_product (1) vía product_order_id
- clients (1) → branch_offices (N) vía client_id
- clients.nit ↔ cartera.nit (join por NIT, sin FK)
- clients.nit ↔ recaudos.nit (join por NIT, sin FK)
- cartera.documento ↔ recaudos.numero_factura (join para ver pagos de una factura)
- products (1) → product_price_history (N) vía product_id (historial de precios)

## PRECIO EFECTIVO — REGLA CRÍTICA
- pop.price puede ser NULL o 0 en órdenes antiguas → SIEMPRE usa: COALESCE(NULLIF(pop.price,0), p.price, 0)
- Esto requiere JOIN products p ON pop.product_id = p.id en TODA consulta que calcule valor
- Precio efectivo por kg: COALESCE(NULLIF(pop.price,0), p.price, 0)
- Valor USD de una línea de OC: pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)
- Valor USD despachado: par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)

## CONVERSIÓN DE MONEDA — CASCADA TRM (4 niveles)
- trm en partials y purchase_orders es el tipo de cambio COP/USD registrado al momento
- Cascada para valor COP: COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)
  → Nivel 1: trm del partial (par.trm)
  → Nivel 2: trm de la OC (po.trm)
  → Nivel 3: última TRM en tabla trm_daily
  → Nivel 4: fallback 4000
- El +0 es necesario porque trm está guardado como string en MariaDB
- Para obtener valor en COP: par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) * COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)
- Para obtener valor en USD: par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)
- NUNCA uses la TRM de hoy para convertir órdenes — cada OC tiene su propia TRM cascada
- Si el usuario pide "valor en pesos" o "valor COP" → aplica cascada TRM
- Si el usuario pide "valor en dólares" o "valor USD" → solo quantity * precio_efectivo

## NORMALIZACIÓN DE EJECUTIVA
- El campo clients.executive puede contener un email (ej: monica.castano@finearom.com)
- Para mostrarlo como nombre legible usa: REPLACE(SUBSTRING_INDEX(executive,'@',1),'.',' ')
- Ejemplo en query: CONCAT(UPPER(LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(executive,'@',1),'.',1),1)), LOWER(SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(executive,'@',1),'.',1),2)), ' ', UPPER(LEFT(SUBSTRING_INDEX(executive,'.',-1),1)), LOWER(SUBSTRING(SUBSTRING_INDEX(executive,'.',-1),2)))
- O simplemente muestra el campo executive tal cual y avisa al usuario que algunos son emails

## QUERIES DE REFERENCIA

Órdenes de un cliente en un período:
SELECT po.order_consecutive, po.status, po.order_creation_date, c.client_name, c.executive, po.trm, po.is_new_win
FROM purchase_orders po JOIN clients c ON po.client_id = c.id
WHERE c.nit = '860001777' AND po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31';

Productos de una OC (con precio correcto para órdenes antiguas):
SELECT p.product_name, p.code, pop.quantity,
  COALESCE(NULLIF(pop.price,0), p.price, 0) precio_kg,
  pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) total_usd,
  pop.new_win, pop.muestra
FROM purchase_order_product pop
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON pop.purchase_order_id = po.id
WHERE po.order_consecutive = '2258-4500302325';

Stats por ejecutiva — pedido vs despachado con cumplimiento (BASE = partials del período):
SELECT REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') AS ejecutiva,
  c.nit AS nit_cliente,
  SUM(pop.quantity) kilos_pedidos,
  ROUND(SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)), 2) total_oc_usd,
  SUM(par.quantity) kilos_despachados,
  ROUND(SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)), 2) despachado_usd,
  ROUND(SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) /
    NULLIF(SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)), 0) * 100, 1) cumplimiento_pct
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id AND pop.muestra = 0
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
WHERE par.type = 'real'
  AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND par.deleted_at IS NULL
GROUP BY c.executive
ORDER BY despachado_usd DESC;
-- NOTA: esta query parte de partials (despachos) e incluye OCs creadas en cualquier mes.
-- NO usar order_creation_date aquí — se perderían ejecutivas con OCs antiguas despachadas en el período.

Participación por ejecutiva en un período (OCs CREADAS, valor USD y COP):
SELECT COALESCE(NULLIF(c.executive,''), 'Sin ejecutiva') ejecutiva,
  COUNT(DISTINCT po.id) ocs,
  SUM(pop.quantity) kilos,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) * COALESCE(NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)) valor_cop
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31' AND pop.muestra = 0
GROUP BY c.executive ORDER BY valor_cop DESC;

Despachos del período por ejecutiva (valor USD y COP — DESPACHADAS):
SELECT COALESCE(NULLIF(c.executive,''), 'Sin ejecutiva') ejecutiva,
  COUNT(DISTINCT po.id) ocs, SUM(par.quantity) kilos,
  SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd,
  SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) * COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)) valor_cop
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
WHERE par.type = 'real' AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31' AND par.deleted_at IS NULL AND pop.muestra = 0
GROUP BY c.executive ORDER BY valor_cop DESC;

Contar órdenes despachadas en un período (mismo criterio que el dashboard):
SELECT COUNT(DISTINCT po.id) ordenes_despachadas
FROM purchase_orders po
WHERE EXISTS (
  SELECT 1 FROM partials par
  JOIN purchase_order_product pop ON par.product_order_id = pop.id
  WHERE par.order_id = po.id AND par.type = 'real'
    AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
    AND par.deleted_at IS NULL AND pop.muestra = 0
);

Cartera detalle por factura (sin GROUP BY — cada fila es una factura):
SELECT nit, nombre_empresa, documento,
  CAST(saldo_contable AS DECIMAL(15,2)) saldo_cop,
  CAST(saldo_vencido AS DECIMAL(15,2)) vencido_cop,
  dias, vence
FROM cartera WHERE fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
ORDER BY CAST(saldo_contable AS DECIMAL(15,2)) DESC;

Cartera AGRUPADA por cliente (usar SUM porque hay múltiples facturas por NIT):
SELECT ca.nit, ca.nombre_empresa,
  SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) saldo_cop,
  SUM(CAST(ca.saldo_vencido AS DECIMAL(15,2))) vencido_cop
FROM cartera ca WHERE ca.fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY ca.nit, ca.nombre_empresa
ORDER BY saldo_cop DESC;

Cartera por tipo (nacional vs internacional):
SELECT catera_type, COUNT(*) facturas,
  SUM(CAST(saldo_contable AS DECIMAL(15,2))) total_cop
FROM cartera WHERE fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY catera_type;

Deuda neta por cliente (saldo contable menos lo ya pagado en recaudos):
SELECT ca.nit, ca.nombre_empresa,
  SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) saldo_bruto,
  COALESCE(SUM(r.valor_cancelado),0) total_pagado,
  GREATEST(SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) - COALESCE(SUM(r.valor_cancelado),0), 0) deuda_neta
FROM cartera ca
LEFT JOIN recaudos r ON r.numero_factura = ca.documento AND r.nit = ca.nit
WHERE ca.fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY ca.nit, ca.nombre_empresa
ORDER BY deuda_neta DESC;

Facturas vencidas (dias negativo) del último snapshot:
SELECT nit, nombre_empresa, documento, fecha, vence,
  ABS(dias) dias_de_mora,
  CAST(saldo_contable AS DECIMAL(15,2)) saldo_cop
FROM cartera
WHERE fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
  AND dias < 0
ORDER BY dias ASC;

Recaudos del período:
SELECT nit, cliente, SUM(valor_cancelado) total_cop, COUNT(*) recibos
FROM recaudos WHERE fecha_recaudo BETWEEN '2026-03-01' AND '2026-03-31'
GROUP BY nit, cliente ORDER BY total_cop DESC;

Evolución diaria de despachos del período (usando snapshot pre-calculado — muy eficiente):
SELECT date, commercial_dispatched_value_usd, dispatched_orders_count,
  dispatch_fulfillment_rate_usd fill_rate_pct,
  pending_dispatch_value_usd pipeline_usd
FROM order_statistics
WHERE date BETWEEN '2026-03-01' AND '2026-03-31'
ORDER BY date;

Fill rate del período (% de kilos pedidos que se despacharon en el período):
-- CORRECTO: partir desde partials con dispatch_date en el período, no desde order_creation_date
SELECT
  SUM(pop.quantity) kilos_pedidos,
  SUM(par.quantity) kilos_despachados,
  ROUND(SUM(par.quantity) / NULLIF(SUM(pop.quantity),0) * 100, 1) fill_rate_pct
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id AND pop.muestra = 0
JOIN purchase_orders po ON po.id = par.order_id
WHERE par.type='real' AND par.deleted_at IS NULL
  AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31';

Historial de precios de un producto:
SELECT p.product_name, ph.price precio_usd_kg, ph.effective_date vigente_desde
FROM product_price_history ph
JOIN products p ON ph.product_id = p.id
WHERE p.product_name LIKE '%BOUSCHET%'
ORDER BY ph.effective_date DESC;

Despachos planeados del período (fecha estimada de despacho dentro del rango):
-- "Planeado" = OCs con despacho programado en el período, priorizando: partial real > partial temporal > po.dispatch_date
SELECT po.order_consecutive, c.client_name, po.status,
  COALESCE(
    (SELECT par_r.dispatch_date FROM partials par_r WHERE par_r.order_id = po.id AND par_r.type='real' AND par_r.deleted_at IS NULL ORDER BY par_r.dispatch_date DESC LIMIT 1),
    (SELECT par_t.dispatch_date FROM partials par_t WHERE par_t.order_id = po.id AND par_t.type='temporal' AND par_t.deleted_at IS NULL ORDER BY par_t.dispatch_date DESC LIMIT 1),
    po.dispatch_date
  ) AS fecha_despacho_estimada,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status IN ('pending','processing','parcial_status')
  AND pop.muestra = 0
  AND COALESCE(
    (SELECT par_r.dispatch_date FROM partials par_r WHERE par_r.order_id = po.id AND par_r.type='real' AND par_r.deleted_at IS NULL ORDER BY par_r.dispatch_date DESC LIMIT 1),
    (SELECT par_t.dispatch_date FROM partials par_t WHERE par_t.order_id = po.id AND par_t.type='temporal' AND par_t.deleted_at IS NULL ORDER BY par_t.dispatch_date DESC LIMIT 1),
    po.dispatch_date
  ) BETWEEN '2026-03-01' AND '2026-03-31'
GROUP BY po.id, c.client_name, po.status
ORDER BY fecha_despacho_estimada;

Lead time real por tipo de cliente (AA/A/B/C):
SELECT c.client_type,
  COUNT(DISTINCT po.id) total_ordenes,
  ROUND(AVG(DATEDIFF(
    (SELECT MIN(par2.dispatch_date) FROM partials par2 WHERE par2.order_id = po.id AND par2.type='real' AND par2.deleted_at IS NULL),
    po.order_creation_date
  ))) avg_dias_reales,
  ROUND(AVG(DATEDIFF(po.required_delivery_date, po.order_creation_date))) avg_dias_solicitados,
  ROUND(100.0 * SUM(CASE WHEN
    (SELECT MIN(par2.dispatch_date) FROM partials par2 WHERE par2.order_id = po.id AND par2.type='real' AND par2.deleted_at IS NULL) <= po.required_delivery_date
    THEN 1 ELSE 0 END) / COUNT(DISTINCT po.id), 1) pct_a_tiempo
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
WHERE po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND c.client_type IN ('AA','A','B','C')
  AND (po.is_muestra = 0 OR po.is_muestra IS NULL)
GROUP BY c.client_type
ORDER BY FIELD(c.client_type,'AA','A','B','C');

Órdenes vencidas / rezagadas (processing sin despachar hace más de 7 días):
SELECT po.order_consecutive, c.client_name, po.status, po.order_creation_date,
  DATEDIFF(CURDATE(), po.order_creation_date) dias_sin_despachar
FROM purchase_orders po JOIN clients c ON po.client_id = c.id
WHERE po.status = 'processing'
  AND po.order_creation_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY dias_sin_despachar DESC;

Despachos con ciudad de entrega (usando branch_offices):
SELECT po.order_consecutive, c.client_name, p.product_name,
  bo.delivery_city ciudad_entrega, bo.name sucursal,
  par.quantity kilos_despachados, par.dispatch_date, par.invoice_number
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
LEFT JOIN branch_offices bo ON pop.branch_office_id = bo.id
WHERE par.type = 'real' AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND par.deleted_at IS NULL AND pop.muestra = 0
ORDER BY par.dispatch_date DESC;

## CONSULTAS POR ROL

### FRANCY — Creación de órdenes

Órdenes creadas hoy por Francy (pendientes de revisión):
SELECT po.order_consecutive, c.client_name, po.order_creation_date, po.status,
  SUM(pop.quantity) kilos_pedidos,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status = 'pending' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date, po.status
ORDER BY po.order_creation_date DESC;

Todas las OC creadas en el período (lo que ingresó Francy):
SELECT po.order_consecutive, c.client_name, po.order_creation_date, po.status,
  po.is_new_win, po.is_muestra,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date, po.status, po.is_new_win, po.is_muestra
ORDER BY po.order_creation_date DESC;

### MARLON — Revisión y fechas estimadas de despacho

OC que Marlon tiene pendientes de revisar (status pending):
SELECT po.order_consecutive, c.client_name, po.order_creation_date,
  DATEDIFF(CURDATE(), po.order_creation_date) dias_esperando,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status = 'pending' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date
ORDER BY dias_esperando DESC;

OC en processing (Marlon revisó, Alexa aún no despacha):
SELECT po.order_consecutive, c.client_name, po.order_creation_date, po.dispatch_date fecha_estimada,
  DATEDIFF(CURDATE(), po.order_creation_date) dias_en_processing,
  (SELECT par_t.dispatch_date FROM partials par_t WHERE par_t.order_id = po.id AND par_t.type='temporal' AND par_t.deleted_at IS NULL ORDER BY par_t.dispatch_date DESC LIMIT 1) fecha_estimada_marlon,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status = 'processing' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date, po.dispatch_date
ORDER BY dias_en_processing DESC;

### ALEXA — Despachos reales

Lo que Alexa despachó en el período (despachos reales):
SELECT po.order_consecutive, c.client_name, par.dispatch_date, par.invoice_number,
  par.tracking_number, par.transporter, par.quantity kilos,
  par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) valor_usd,
  COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), 4000) trm_usada
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
WHERE par.type = 'real' AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND par.deleted_at IS NULL AND pop.muestra = 0
ORDER BY par.dispatch_date DESC;

OC con despacho parcial — qué queda pendiente para Alexa (parcial_status):
-- Muestra kilos pedidos, kilos ya despachados y kilos pendientes por OC
SELECT po.order_consecutive, c.client_name, po.order_creation_date,
  SUM(pop.quantity) kilos_pedidos,
  COALESCE(SUM(par.quantity), 0) kilos_despachados,
  SUM(pop.quantity) - COALESCE(SUM(par.quantity), 0) kilos_pendientes,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_total_usd,
  SUM(COALESCE(par.quantity,0) * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_despachado_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
LEFT JOIN partials par ON par.order_id = po.id AND par.product_order_id = pop.id
  AND par.type = 'real' AND par.deleted_at IS NULL
WHERE po.status = 'parcial_status' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date
HAVING kilos_pendientes > 0
ORDER BY po.order_creation_date;
SCHEMA;
    }
}

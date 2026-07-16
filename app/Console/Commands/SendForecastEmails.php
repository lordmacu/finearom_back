<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Mail\ForecastWeeklyMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendForecastEmails extends Command
{
    protected $signature = 'forecast:send-emails {--force : Forzar envío sin verificar configuración de día}';
    protected $description = 'Envía emails de seguimiento de pronósticos a las ejecutivas según la configuración.';

    private const DIAS_SEMANA = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    private const MESES = [
        1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',
        7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE',
    ];

    public function handle(): int
    {
        $config = DB::table('forecast_email_config')->first();

        if (!$config || !$config->enabled) {
            // Corre cada minuto; si no está habilitado no logueamos nada para no
            // llenar el log.
            return 0;
        }

        $now = Carbon::now('America/Bogota');

        // 1. Validar HORA configurada (HH:mm). Corre cada minuto → solo dispara
        //    en el minuto exacto de send_hour.
        if (!$this->option('force')) {
            $sendHour = $this->normalizeHour($config->send_hour ?? '08:00');
            if ($now->format('H:i') !== $sendHour) {
                return 0;
            }
        }

        // 2. Validar que haya al menos una regla de día configurada.
        $rules = json_decode($config->schedule_rules ?? '[]', true);
        if (!$this->option('force') && empty($rules)) {
            Log::warning('forecast:send-emails: enabled=1 pero schedule_rules vacío — no se envía nada. Configurá al menos un día en /settings/general?tab=forecast-email');
            $this->warn('schedule_rules vacío — no hay días configurados.');
            return 0;
        }

        // 3. Validar que HOY sea uno de los días configurados (soporta hasta 3 reglas).
        if (!$this->option('force') && !$this->shouldRunToday($config)) {
            $this->line('Hoy no corresponde enviar según schedule_rules.');
            return 0;
        }

        $año      = $now->year;
        $mes      = self::MESES[$now->month];
        $mesStart = $now->copy()->startOfMonth()->toDateString();
        $mesEnd   = $now->copy()->endOfMonth()->toDateString();
        $semana   = (int) ceil($now->day / 7);

        $this->info("Generando emails de pronóstico — {$mes} {$año}");
        Log::info("forecast:send-emails disparado — {$mes} {$año} — hora={$now->format('H:i')} — reglas=" . count($rules));

        // Obtener ejecutivas que tienen clientes con pronósticos manuales este mes
        $ejecutivas = DB::table('clients as c')
            ->join('sales_forecasts as sf', function ($j) use ($año, $mes) {
                $j->on(DB::raw('sf.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'))
                  ->where('sf.modelo', 'manual')
                  ->where('sf.año', (string) $año)
                  ->where('sf.mes', $mes);
            })
            ->whereNotNull('c.executive_email')
            ->where('c.executive_email', '!=', '')
            ->select('c.executive_email', 'c.executive')
            ->distinct()
            ->get();

        if ($ejecutivas->isEmpty()) {
            $this->warn('No hay ejecutivas con pronósticos manuales para este mes.');
            return 0;
        }

        $enviados = 0;

        // Emails alternativos (configurados en /settings/general?tab=forecast-email)
        // Se agregan como destinatarios adicionales a TODO envío — no se validan
        // contra la tabla `executives` porque son copias fijas para seguimiento.
        $fallbackRaw = $config->fallback_emails ?? null;
        $fallbackEmails = [];
        if (!empty($fallbackRaw)) {
            $decoded = is_array($fallbackRaw) ? $fallbackRaw : json_decode((string) $fallbackRaw, true);
            if (is_array($decoded)) {
                $fallbackEmails = array_values(array_filter(array_map(
                    fn ($e) => strtolower(trim((string) $e)),
                    $decoded
                )));
            }
        }
        if (!empty($fallbackEmails)) {
            $this->info('Emails alternativos configurados: ' . implode(', ', $fallbackEmails));
        }

        foreach ($ejecutivas as $exec) {
            $emails = $this->parseEmails($exec->executive_email);
            if (empty($emails)) continue;

            // Guardia: solo enviar a emails registrados en la tabla executives (activos)
            $emailsValidos = array_values(array_filter($emails, function (string $email) {
                return DB::table('executives')
                    ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
                    ->where('is_active', true)
                    ->exists();
            }));

            $rechazados = array_diff($emails, $emailsValidos);
            foreach ($rechazados as $r) {
                $this->warn("  ⚠ Email ignorado (no es ejecutivo activo): {$r}");
                Log::warning("SendForecastEmails: email ignorado porque no está en tabla executives — {$r}");
            }

            if (empty($emailsValidos)) continue;

            $data = $this->buildEmailData($exec, $año, $mes, $mesStart, $mesEnd, $semana);
            if (empty($data['clientes'])) continue;

            // TO = ejecutiva(s) válida(s) — destinatario principal
            $toList = array_values(array_unique(array_map(
                fn ($e) => strtolower(trim($e)),
                $emailsValidos
            )));
            // CC = emails alternativos (en copia) — excluyendo los que ya están en TO
            $ccList = array_values(array_diff($fallbackEmails, $toList));

            try {
                $mailer = Mail::to($toList);
                if (!empty($ccList)) {
                    $mailer->cc($ccList);
                }
                $mailer->send(new ForecastWeeklyMail($data));
                $ccMsg = empty($ccList) ? '' : ' | cc: ' . implode(', ', $ccList);
                $this->line("  ✓ Enviado a " . implode(', ', $toList) . $ccMsg);
                $enviados++;
            } catch (\Throwable $e) {
                $this->error("  ✗ Error enviando a " . implode(', ', $toList) . ": " . $e->getMessage());
                Log::error('SendForecastEmails [' . implode(', ', $toList) . ']: ' . $e->getMessage());
            }
        }

        $this->info("Emails enviados: {$enviados}");
        return 0;
    }

    /**
     * Normaliza a HH:mm — admite "H:i", "H:i:s" o "H:i".
     */
    private function normalizeHour(string $raw): string
    {
        $raw = trim($raw);
        // Quedarnos solo con HH:mm aunque venga HH:mm:ss
        $parts = explode(':', $raw);
        $h = str_pad((string) (int) ($parts[0] ?? '0'), 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) (int) ($parts[1] ?? '0'), 2, '0', STR_PAD_LEFT);
        return "{$h}:{$m}";
    }

    private function shouldRunToday(object $config): bool
    {
        $today = Carbon::now('America/Bogota');
        $rules = json_decode($config->schedule_rules ?? '[]', true);

        if (empty($rules)) return false;

        foreach ($rules as $rule) {
            $week      = (int) ($rule['week'] ?? 0); // 1-4
            $isoDay    = (int) ($rule['day']  ?? 0); // 1=Lun … 7=Dom (ISO)
            $carbonDay = $isoDay % 7;                // Carbon: 0=Dom, 1=Lun … 6=Sáb

            try {
                $target = Carbon::create($today->year, $today->month, 1)
                    ->nthOfMonth($week, $carbonDay);
                if ($target && $today->toDateString() === $target->toDateString()) {
                    return true;
                }
            } catch (\Throwable) {
                // semana inexistente en este mes (ej: 5to lunes)
            }
        }

        return false;
    }

    private function buildEmailData(object $exec, int $año, string $mes, string $mesStart, string $mesEnd, int $semana): array
    {
        // Clientes de esta ejecutiva con pronóstico manual este mes.
        // NOTA: la tabla `products` tiene códigos repetidos (distintos product_id
        // con el mismo `code`). Para evitar que cada forecast se multiplique por
        // cuantos productos compartan su código, hacemos el JOIN contra un subquery
        // que devuelve UN solo product_id por code (el más antiguo / menor id).
        $productsUniqCode = DB::table('products')
            ->selectRaw('code, MIN(id) as id')
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->groupBy('code');

        $forecasts = DB::table('sales_forecasts as sf')
            ->join('clients as c', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.nit COLLATE utf8mb4_unicode_ci'))
            ->joinSub($productsUniqCode, 'pu', function ($j) {
                $j->on(DB::raw('pu.code COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.codigo COLLATE utf8mb4_unicode_ci'));
            })
            ->join('products as p', 'p.id', '=', 'pu.id')
            ->where('sf.modelo', 'manual')
            ->where('sf.año', (string) $año)
            ->where('sf.mes', $mes)
            ->where('c.executive_email', $exec->executive_email)
            ->select('c.nit', 'c.client_name', 'sf.codigo', 'p.product_name', 'sf.cantidad_forecast', 'p.price as product_price')
            ->orderBy('c.client_name')
            ->orderBy('p.product_name')
            ->get();

        // Partials REAL = facturado / despachado
        $despachados = DB::table('partials as pt')
            ->join('purchase_orders as po', 'pt.order_id', '=', 'po.id')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('pt.type', 'real')
            ->whereNull('pt.deleted_at')
            ->whereBetween('pt.dispatch_date', [$mesStart, $mesEnd])
            ->where('pop.muestra', 0)
            ->where('c.executive_email', $exec->executive_email)
            ->selectRaw('c.nit, p.code as codigo,
                SUM(pt.quantity) as cantidad_real,
                SUM((CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity) as value_usd')
            ->groupBy('c.nit', 'p.code')
            ->get()
            ->keyBy(fn ($r) => $r->nit . '|' . $r->codigo);

        // Partials TEMPORAL = pendiente por despachar (lo que Marlon programó y Alexa aún NO despachó).
        //
        // ⚠ Se RESTA lo ya despachado de la misma línea. Antes se sumaba el temporal completo, y como
        // el temporal NO se borra cuando Alexa despacha, los mismos kilos se contaban dos veces:
        // 1.784 de 1.814 líneas de 2026 (98%) tienen temporal == real exacto. En julio 2026 eso metía
        // 7.166 kg ya despachados dentro de "pendiente" e inflaba el cumplimiento del correo
        // (Ortega figuraba en 91,8% cuando su cumplimiento real era ~50%; Pardo daba 154%).
        //
        // pendiente_por_linea = GREATEST(temporal_del_mes - real_ya_despachado, 0)
        //   · línea programada y despachada igual  -> 0 (era el duplicado)
        //   · línea despachada a medias            -> solo lo que falta
        //   · línea programada sin despachar       -> el temporal completo
        // El real NO se acota al mes a propósito: si la línea ya salió (aunque fuera antes), no está pendiente.
        //
        // Se agrupa por columnas base (pop.price, p.price) y NO por la expresión CASE: con
        // ONLY_FULL_GROUP_BY, MariaDB no reconoce expresiones complejas repetidas en el GROUP BY.
        $pendientes = collect(DB::select("
            SELECT nit, codigo,
                   SUM(pendiente) AS cantidad_pendiente,
                   SUM(pendiente * precio) AS value_usd
            FROM (
                SELECT c.nit AS nit,
                       p.code AS codigo,
                       (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) AS precio,
                       GREATEST(
                           SUM(CASE WHEN pt.type = 'temporal' AND pt.dispatch_date BETWEEN ? AND ?
                                    THEN pt.quantity ELSE 0 END)
                         - SUM(CASE WHEN pt.type = 'real' THEN pt.quantity ELSE 0 END),
                       0) AS pendiente
                FROM partials pt
                JOIN purchase_orders po ON po.id = pt.order_id
                JOIN clients c ON c.id = po.client_id
                JOIN purchase_order_product pop ON pop.id = pt.product_order_id
                JOIN products p ON p.id = pop.product_id
                WHERE pt.deleted_at IS NULL
                  AND pop.muestra = 0
                  AND c.executive_email = ?
                GROUP BY pop.id, c.nit, p.code, pop.price, p.price
            ) t
            GROUP BY nit, codigo
        ", [$mesStart, $mesEnd, $exec->executive_email]))
            ->keyBy(fn ($r) => $r->nit . '|' . $r->codigo);

        $clientesMap       = [];
        $totalPronostico   = 0;
        $totalVendido      = 0;
        $totalPendiente    = 0;
        $totalPronUsd      = 0.0;
        $totalVendidoUsd   = 0.0;
        $totalPendienteUsd = 0.0;

        foreach ($forecasts as $f) {
            $key         = $f->nit . '|' . $f->codigo;
            $pronostico  = (int) $f->cantidad_forecast;
            $vendido     = (int) ($despachados[$key]->cantidad_real ?? 0);
            $pendiente   = (int) ($pendientes[$key]->cantidad_pendiente ?? 0);
            $pronUsd     = round($pronostico * (float) $f->product_price, 2);
            $vendUsd     = round((float) ($despachados[$key]->value_usd ?? 0), 2);
            $pendUsd     = round((float) ($pendientes[$key]->value_usd ?? 0), 2);
            // Cumplimiento = (facturado + pendiente) / pronosticado
            $cumplimiento = $pronostico > 0
                ? round((($vendido + $pendiente) / $pronostico) * 100, 1)
                : null;

            $clientesMap[$f->nit]['nombre'] = $f->client_name;
            $clientesMap[$f->nit]['productos'][] = [
                'nombre'        => $f->product_name,
                'codigo'        => $f->codigo,
                'pronostico'    => $pronostico,
                'vendido'       => $vendido,
                'pendiente'     => $pendiente,
                'cumplimiento'  => $cumplimiento,
                'pron_usd'      => $pronUsd,
                'vendido_usd'   => $vendUsd,
                'pendiente_usd' => $pendUsd,
            ];
            $totalPronostico   += $pronostico;
            $totalVendido      += $vendido;
            $totalPendiente    += $pendiente;
            $totalPronUsd      += $pronUsd;
            $totalVendidoUsd   += $vendUsd;
            $totalPendienteUsd += $pendUsd;
        }

        $execName = DB::table('executives')
            ->whereRaw('LOWER(email) = ?', [strtolower($exec->executive_email)])
            ->value('name') ?? ($exec->executive ?: $exec->executive_email);

        return [
            'ejecutiva'           => $execName,
            'mes'                 => ucfirst(strtolower($mes)),
            'año'                 => $año,
            'semana'              => $semana,
            'clientes'            => array_values($clientesMap),
            'total_pronostico'    => $totalPronostico,
            'total_vendido'       => $totalVendido,
            'total_pendiente'     => $totalPendiente,
            'total_pron_usd'      => $totalPronUsd,
            'total_vendido_usd'   => $totalVendidoUsd,
            'total_pendiente_usd' => $totalPendienteUsd,
            'total_cumplimiento'  => $totalPronostico > 0
                ? round((($totalVendido + $totalPendiente) / $totalPronostico) * 100, 1)
                : null,
        ];
    }

    private function parseEmails(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_filter($decoded);
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}

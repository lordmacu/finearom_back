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
            $this->line('Envío de pronósticos deshabilitado.');
            return 0;
        }

        if (!$this->option('force') && !$this->shouldRunToday($config)) {
            $this->line('Hoy no corresponde enviar según la configuración.');
            return 0;
        }

        $now      = Carbon::now('America/Bogota');
        $año      = $now->year;
        $mes      = self::MESES[$now->month];
        $mesStart = $now->copy()->startOfMonth()->toDateString();
        $mesEnd   = $now->copy()->endOfMonth()->toDateString();
        $semana   = (int) ceil($now->day / 7);

        $this->info("Generando emails de pronóstico — {$mes} {$año} (Semana {$semana})");

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

            foreach ($emailsValidos as $email) {
                try {
                    Mail::to($email)->send(new ForecastWeeklyMail($data));
                    $this->line("  ✓ Enviado a {$email}");
                    $enviados++;
                } catch (\Throwable $e) {
                    $this->error("  ✗ Error enviando a {$email}: " . $e->getMessage());
                    Log::error("SendForecastEmails: {$email} — " . $e->getMessage());
                }
            }
        }

        $this->info("Emails enviados: {$enviados}");
        return 0;
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
        // Clientes de esta ejecutiva con pronóstico manual este mes
        $forecasts = DB::table('sales_forecasts as sf')
            ->join('clients as c', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.nit COLLATE utf8mb4_unicode_ci'))
            ->join('products as p', DB::raw('p.code COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.codigo COLLATE utf8mb4_unicode_ci'))
            ->where('sf.modelo', 'manual')
            ->where('sf.año', (string) $año)
            ->where('sf.mes', $mes)
            ->where('c.executive_email', $exec->executive_email)
            ->select('c.nit', 'c.client_name', 'sf.codigo', 'p.product_name', 'sf.cantidad_forecast', 'p.price as product_price')
            ->orderBy('c.client_name')
            ->orderBy('p.product_name')
            ->get();

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

        $clientesMap     = [];
        $totalPronostico = 0;
        $totalVendido    = 0;
        $totalPronUsd    = 0.0;
        $totalVendidoUsd = 0.0;

        foreach ($forecasts as $f) {
            $key          = $f->nit . '|' . $f->codigo;
            $vendido      = (int) ($despachados[$key]->cantidad_real ?? 0);
            $pronostico   = (int) $f->cantidad_forecast;
            $cumplimiento = $pronostico > 0 ? round($vendido / $pronostico * 100, 1) : null;
            $pronUsd      = round($pronostico * (float) $f->product_price, 2);
            $vendUsd      = round((float) ($despachados[$key]->value_usd ?? 0), 2);

            $clientesMap[$f->nit]['nombre'] = $f->client_name;
            $clientesMap[$f->nit]['productos'][] = [
                'nombre'       => $f->product_name,
                'codigo'       => $f->codigo,
                'pronostico'   => $pronostico,
                'vendido'      => $vendido,
                'cumplimiento' => $cumplimiento,
                'pron_usd'     => $pronUsd,
                'vendido_usd'  => $vendUsd,
            ];
            $totalPronostico += $pronostico;
            $totalVendido    += $vendido;
            $totalPronUsd    += $pronUsd;
            $totalVendidoUsd += $vendUsd;
        }

        $execName = DB::table('executives')
            ->whereRaw('LOWER(email) = ?', [strtolower($exec->executive_email)])
            ->value('name') ?? ($exec->executive ?: $exec->executive_email);

        return [
            'ejecutiva'          => $execName,
            'mes'                => ucfirst(strtolower($mes)),
            'año'                => $año,
            'semana'             => $semana,
            'clientes'           => array_values($clientesMap),
            'total_pronostico'   => $totalPronostico,
            'total_vendido'      => $totalVendido,
            'total_pron_usd'     => $totalPronUsd,
            'total_vendido_usd'  => $totalVendidoUsd,
            'total_cumplimiento' => $totalPronostico > 0
                ? round($totalVendido / $totalPronostico * 100, 1)
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

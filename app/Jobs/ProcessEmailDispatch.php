<?php

namespace App\Jobs;

use App\Mail\EstadoCarteraMail;
use App\Models\Cartera;
use App\Models\EmailDispatchQueue;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessEmailDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $emailDispatchId = null;
    public ?EmailDispatchQueue $emailDispatch = null;

    public function __construct(EmailDispatchQueue|int $emailDispatch)
    {
        if ($emailDispatch instanceof EmailDispatchQueue) {
            // Nuevo formato: guardar ID para evitar problemas de serialización
            $this->emailDispatchId = $emailDispatch->id;
            // También guardar el modelo para compatibilidad con jobs antiguos
            $this->emailDispatch = $emailDispatch;
        } else {
            $this->emailDispatchId = $emailDispatch;
        }
    }

    public function handle(): void
    {
        try {
            // Obtener el ID desde donde esté disponible (compatibilidad con jobs antiguos y nuevos)
            $dispatchId = $this->emailDispatchId ?? $this->emailDispatch?->id ?? null;
            
            if (!$dispatchId) {
                Log::warning('Email dispatch job missing ID - deleting incompatible job', [
                    'job' => static::class,
                    'emailDispatchId' => $this->emailDispatchId,
                    'emailDispatch_exists' => isset($this->emailDispatch),
                ]);
                // Eliminar este job de la cola ya que no tiene información válida
                $this->delete();
                return;
            }

            $emailDispatch = EmailDispatchQueue::find($dispatchId);

            // Verificar si el modelo fue eliminado
            if (! $emailDispatch) {
                Log::warning('Email dispatch job missing model - possibly deleted', [
                    'job' => static::class,
                    'dispatch_id' => $dispatchId,
                ]);
                return;
            }

        try {
            $emailDispatch->update(['send_status' => 'sending']);

            $recipients = explode(
                ',',
                $emailDispatch->email_type === 'order_block'
                    ? $emailDispatch->order_block_notification_emails
                    : $emailDispatch->outstanding_balance_notification_emails
            );

            $dataEmail = $this->cargarPorNit($emailDispatch->due_date, $emailDispatch->client_nit);

            // Enviar solo si el send_status no es 'sent' y hay datos válidos
            if ($emailDispatch->send_status != 'sent') {
                if ($emailDispatch->email_type == 'order_block') {
                    // Para order_block: solo enviar si hay productos y total vencido > 0
                    if (is_array($dataEmail) && count($dataEmail['products']) > 0) {
                        if ($dataEmail['total_vencidos'] > 0) {
                            Mail::mailer('google_alt')->to($recipients)->send(new EstadoCarteraMail($dataEmail, $emailDispatch->email_type));
                        }
                    }
                } else {
                    // Para outstanding_balance: enviar si hay datos válidos
                    if (is_array($dataEmail)) {
                        Mail::mailer('google_alt')->to($recipients)->send(new EstadoCarteraMail($dataEmail, $emailDispatch->email_type));
                    }
                }
            }

            $emailDispatch->update([
                'send_status' => 'sent',
                'email_sent_date' => now(),
                'error_message' => null,
            ]);
        } catch (Exception $e) {
            $this->logFailure($e->getMessage(), $emailDispatch, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 'error');

            $emailDispatch->update([
                'send_status' => 'failed',
                'error_message' => $this->formatExceptionForDb($e),
            ]);
        }
    }

    private function cargarPorNit(string $fecha, string $nit)
    {
        $cartera = Cartera::with(['client'])
            ->where('fecha_cartera', $fecha)
            ->whereHas('client', fn ($q) => $q->where('nit', $nit))
            ->get();

        if ($cartera->isEmpty()) {
            return 0;
        }

        $cartera = $cartera->map(function (Cartera $item) {
            $item->client_nit = $item->client->nit ?? null;
            $item->client_name = $item->client->client_name ?? null;
            $item->ejecutiva = $item->client->executive ?? null;
            $item->dispatch_confirmation_email = $item->client->dispatch_confirmation_email ?? null;
            $item->products = $this->getOrdersWithFilteredProducts($item->client->id, $item->fecha_from, $item->fecha_to);

            unset($item->client);
            $item->catera_type = $item->catera_type ?? null;

            $item->saldo_vencido = is_numeric($item->saldo_vencido) ? (float) $item->saldo_vencido : 0.0;
            $item->saldo_contable = is_numeric($item->saldo_contable) ? (float) $item->saldo_contable : 0.0;
            $item->saldo_vencido_texto = $this->numeroATexto((float) $item->saldo_vencido, $item->catera_type);
            $item->saldo_contable_texto = $this->numeroATexto((float) $item->saldo_contable, $item->catera_type);

            return $item;
        });

        $group = $cartera->groupBy('client_nit')->map(function ($items) {
            $first = $items->first();
            $isNacional = 'nacional';

            $sorted = $items->sortBy('dias')->values()->map(function ($item) {
                return [
                    'documento' => $item->documento,
                    'document_array' => $this->extractDocumentPart($item->documento),
                    'fecha_cartera' => $item->fecha_cartera,
                    'fecha' => $item->fecha,
                    'dias' => $item->dias,
                    'vence' => $item->vence,
                    'saldo_vencido' => (float) $item->saldo_vencido,
                    'saldo_contable' => (float) $item->saldo_contable,
                    'saldo_vencido_texto' => $item->saldo_vencido_texto,
                    'saldo_contable_texto' => $item->saldo_contable_texto,
                    'catera_type' => $item->catera_type,
                ];
            })->all();

            $total_vencidos = $items->filter(fn ($i) => $i->dias < 0)->sum(fn ($i) => (float) $i->saldo_contable);
            $total_por_vencer = $items->filter(fn ($i) => $i->dias > 0)->sum(fn ($i) => (float) $i->saldo_contable);
            $total_por_vencer += $total_vencidos;

            if (count($sorted) > 0 && $sorted[0]['catera_type'] === 'internacional') {
                $isNacional = 'internacional';
            }

            $emails = explode(',', $first->dispatch_confirmation_email ?? '');
            if ($first->client_name === null) {
                $emails = [];
            }

            return [
                'dispatch_confirmation_email' => $emails,
                'client_name' => $first->client_name,
                'ejecutiva' => $first->ejecutiva,
                'total_vencidos' => $total_vencidos,
                'total_vencidos_text' => $this->numeroATexto((float) $total_vencidos, $isNacional),
                'total_por_vencer' => $total_por_vencer,
                'total_por_vencer_text' => $this->numeroATexto((float) $total_por_vencer, $isNacional),
                'nit' => $first->client_nit,
                'cuentas' => $sorted,
                'products' => $first->products,
                'emails' => $emails,
                'isChecked' => true,
            ];
        })->values()->first();

        return $group;
    }

    private function getOrdersWithFilteredProducts($clientId, $from, $to)
    {
        $query = "
        SELECT
            po.id AS purchase_order_id,
            po.order_consecutive,
            po.client_id,
            pop.product_id,
            pop.quantity,
            CASE
                WHEN pop.muestra = 1 THEN 0
                WHEN pop.price > 0 THEN pop.price
                ELSE p.price
            END AS price,
            pop.branch_office_id,
            pop.new_win,
            pop.muestra,
            partials.dispatch_date,
            p.product_name AS product_name
        FROM purchase_orders po
        INNER JOIN purchase_order_product pop ON po.id = pop.purchase_order_id
        INNER JOIN products p ON pop.product_id = p.id
        INNER JOIN partials ON partials.order_id = po.id AND partials.product_id = pop.product_id
        WHERE po.client_id = :client_id
        AND partials.dispatch_date BETWEEN :start_date AND :end_date
        AND partials.type = 'temporal'
    ";

        return DB::select($query, [
            'client_id' => $clientId,
            'start_date' => $from,
            'end_date' => $to,
        ]);
    }

    private function numeroATexto($numero, $cateraType)
    {
        $esInternacional = $cateraType === 'internacional';

        $numero = trim(str_replace('$', '', $numero));
        $numero = preg_replace('/\.(?=\d{3}(?:\.|,))/', '', $numero);
        $numero = str_replace(',', '.', $numero);
        $numero = floatval($numero);

        $partes = explode('.', number_format($numero, 2, '.', ''));

        $formatter = new \NumberFormatter('es', \NumberFormatter::SPELLOUT);
        $texto_entero = ucfirst($formatter->format($partes[0]));
        $texto_decimal = isset($partes[1]) && $partes[1] !== '00'
            ? ' con ' . $partes[1] . ' centavos'
            : '';

        $moneda = $esInternacional
            ? ' dólares estadounidenses en moneda de curso legal'
            : ' pesos colombianos en moneda de circulación corriente';

        return $texto_entero . $texto_decimal . $moneda;
    }

    private function extractDocumentPart($document)
    {
        $pattern = '/^(.*?-\d+-000000)(\d+)(-.*)$/';

        if (! preg_match($pattern, $document, $matches)) {
            return [
                'prefix' => $document,
                'highlight' => '',
                'suffix' => '',
            ];
        }

        return [
            'prefix' => $matches[1],
            'highlight' => $matches[2],
            'suffix' => $matches[3],
        ];
    }

    /**
     * Log with consistent context for debugging email dispatch failures.
     *
     * @param array<string,mixed> $extra
     */
    private function logFailure(string $message, EmailDispatchQueue $emailDispatch, array $extra = [], string $level = 'error'): void
    {
        $context = array_merge([
            'email_dispatch_id' => $emailDispatch->id ?? null,
            'email_type' => $emailDispatch->email_type ?? null,
            'client_nit' => $emailDispatch->client_nit ?? null,
            'due_date' => $emailDispatch->due_date ?? null,
        ], $extra);

        if ($level === 'warning') {
            Log::warning($message, $context);
            return;
        }

        Log::error($message, $context);
    }

    private function formatExceptionForDb(Exception $e): string
    {
        $summary = sprintf(
            '[%s] %s (%s:%d)',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        return mb_substr($summary, 0, 255);
    }

    /**
     * Manejar el fallo del job.
     * Se ejecuta cuando Laravel no puede deserializar el modelo.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('Email dispatch job failed - Model may have been deleted', [
            'job' => static::class,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
    }
}

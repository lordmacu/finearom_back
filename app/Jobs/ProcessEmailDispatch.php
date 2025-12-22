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
use Illuminate\Support\Facades\Mail;

class ProcessEmailDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly EmailDispatchQueue $emailDispatch)
    {
    }

    public function handle(): void
    {
        try {
            $this->emailDispatch->update(['send_status' => 'sending']);

            $recipients = explode(
                ',',
                $this->emailDispatch->email_type === 'order_block'
                    ? $this->emailDispatch->order_block_notification_emails
                    : $this->emailDispatch->outstanding_balance_notification_emails
            );

            $dataEmail = $this->cargarPorNit($this->emailDispatch->due_date, $this->emailDispatch->client_nit);
            if ($dataEmail === null) {
                $this->emailDispatch->update([
                    'send_status' => 'failed',
                    'error_message' => 'No data found for this NIT/date',
                ]);
                return;
            }

            // Enviar solo si hay correo configurado
            $hasRecipients = collect($recipients)->filter()->isNotEmpty();
            if (! $hasRecipients) {
                $this->emailDispatch->update([
                    'send_status' => 'failed',
                    'error_message' => 'No recipients provided',
                ]);
                return;
            }

            // Reglas heredadas: si es bloqueo, solo envía cuando hay productos y total vencido > 0
            if ($this->emailDispatch->email_type === 'order_block') {
                $hasProducts = ! empty($dataEmail['products']);
                $hasOverdue = ($dataEmail['total_vencidos'] ?? 0) > 0;
                if (! $hasProducts || ! $hasOverdue) {
                    $this->emailDispatch->update([
                        'send_status' => 'failed',
                        'error_message' => 'Sin productos o sin saldo vencido para bloqueo',
                    ]);
                    return;
                }
            }

            Mail::to($recipients)->send(new EstadoCarteraMail($dataEmail, $this->emailDispatch->email_type));

            $this->emailDispatch->update([
                'send_status' => 'sent',
                'email_sent_date' => now(),
                'error_message' => null,
            ]);
        } catch (Exception $e) {
            $this->emailDispatch->update([
                'send_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function cargarPorNit(string $fecha, string $nit): ?array
    {
        $cartera = Cartera::with(['client'])
            ->where('fecha_cartera', $fecha)
            ->whereHas('client', fn ($q) => $q->where('nit', $nit))
            ->get();

        if ($cartera->isEmpty()) {
            return null;
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
            p.price,
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
}


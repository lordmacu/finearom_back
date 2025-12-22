<?php

namespace App\Queries\Cartera;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CarteraQuery
{
    /**
     * Normalize filters from query params.
     *
     * @param array<string,mixed> $params
     * @return array{executive_email?:string,client_id?:int,catera_type?:string}
     */
    public function filtersFromParams(array $params): array
    {
        $filters = [];

        if (! empty($params['executive_email'])) {
            $filters['executive_email'] = strtolower(trim((string) $params['executive_email']));
        }

        if (! empty($params['client_id'])) {
            $filters['client_id'] = (int) $params['client_id'];
        }

        if (! empty($params['catera_type'])) {
            $filters['catera_type'] = (string) $params['catera_type'];
        }

        return $filters;
    }

    /**
     * @param array{executive_email?:string,client_id?:int,catera_type?:string} $filters
     * @return array{projected_from_partials:float,current_debt:float,overdue_debt:float,projection_vs_debt_diff:float}
     */
    public function summary(Carbon $from, Carbon $to, array $filters): array
    {
        $projectedFromPartials = $this->projectedFromPartials($from, $to, $filters);
        $currentDebt = $this->currentDebt($filters);
        $overdueDebt = $this->overdueDebt($filters);

        return [
            'projected_from_partials' => round($projectedFromPartials, 2),
            'current_debt' => round($currentDebt, 2),
            'overdue_debt' => round($overdueDebt, 2),
            'projection_vs_debt_diff' => round($projectedFromPartials - $currentDebt, 2),
        ];
    }

    /**
     * Totales por factura (con estado) dentro del rango por fecha de recaudo.
     *
     * @param array{executive_email?:string,client_id?:int,catera_type?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function clients(Carbon $from, Carbon $to, array $filters): array
    {
        $snapshotDate = $this->latestSnapshotDate($filters['catera_type'] ?? null);
        $today = Carbon::now('America/Bogota')->toDateString();

        $query = DB::table('recaudos as r')
            ->leftJoin('cartera as car', function ($join) use ($snapshotDate) {
                $join->on('r.numero_factura', '=', 'car.documento')
                    ->where('car.fecha_cartera', '=', $snapshotDate);
            })
            ->leftJoin('clients as c', 'r.nit', '=', 'c.nit')
            ->whereBetween('r.fecha_recaudo', [$from->toDateString(), $to->toDateString()])
            ->select(
                'c.id as client_id',
                'c.client_name',
                'r.nit',
                'r.numero_factura',
                'r.fecha_recaudo',
                'r.valor_cancelado',
                'r.fecha_vencimiento',
            )
            ->selectRaw('MAX(car.catera_type) as catera_type')
            ->selectRaw('SUM(CASE WHEN car.documento IS NOT NULL THEN r.valor_cancelado ELSE 0 END) AS current_debt')
            ->selectRaw(
                'SUM(CASE WHEN car.documento IS NOT NULL AND r.fecha_vencimiento < ? THEN r.valor_cancelado ELSE 0 END) AS overdue_amount',
                [$today]
            );

        if (! empty($filters['client_id'])) {
            $query->where('c.id', (int) $filters['client_id']);
        }

        if (! empty($filters['executive_email'])) {
            $email = strtolower(trim($filters['executive_email']));
            $query->where(function ($q) use ($email) {
                $q->whereRaw('FIND_IN_SET(?, REPLACE(LOWER(c.executive_email), " ", "")) > 0', [$email])
                    ->orWhereRaw('JSON_SEARCH(c.executive_email, "one", ?) IS NOT NULL', [$email]);
            });
        }

        if (! empty($filters['catera_type'])) {
            $type = (string) $filters['catera_type'];
            $query->whereNotNull('car.documento');

            if ($type === 'internacional') {
                $query->where('car.catera_type', '=', 'internacional');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('car.catera_type')->orWhere('car.catera_type', '=', 'nacional');
                });
            }
        }

        $rows = $query
            ->groupBy(
                'c.id',
                'c.client_name',
                'r.nit',
                'r.numero_factura',
                'r.fecha_recaudo',
                'r.valor_cancelado',
                'r.fecha_vencimiento',
            )
            ->orderBy('c.client_name')
            ->orderBy('r.numero_factura')
            ->get();

        $mapped = $this->mapClientRows($rows, $today);
        if ($mapped->isNotEmpty()) {
            return $mapped->values()->toArray();
        }

        // Fallback: si no hay recaudos en el período seleccionado, mostrar la foto de cartera (snapshotDate).
        $carteraRows = DB::table('cartera as car')
            ->leftJoin('clients as c', 'car.nit', '=', 'c.nit')
            ->where('car.fecha_cartera', '=', $snapshotDate)
            ->select(
                'c.id as client_id',
                'c.client_name',
                'car.nit',
                'car.documento as numero_factura',
                DB::raw('NULL as fecha_recaudo'),
                DB::raw('car.saldo_contable as valor_cancelado'),
                'car.vence as fecha_vencimiento',
                'car.catera_type',
                'car.saldo_contable as current_debt',
                DB::raw('car.saldo_contable as overdue_amount')
            )
            ->orderBy('c.client_name')
            ->orderBy('car.documento')
            ->get();

        // Ajustar overdue usando fecha de vencimiento comparada con hoy.
        $carteraRows = $carteraRows->map(function ($row) use ($today) {
            $row->overdue_amount = ($row->fecha_vencimiento && $row->fecha_vencimiento < $today)
                ? $row->current_debt
                : 0;
            return $row;
        });

        return $this->mapClientRows($carteraRows, $today)->values()->toArray();
    }

    /**
     * Ejecutivas para filtros (desde clients.executive_email).
     *
     * @return array<int,array{email:string,name:string}>
     */
    public function executives(): array
    {
        $clients = DB::table('clients')
            ->whereNotNull('executive_email')
            ->where('executive_email', '!=', '')
            ->select('executive_email')
            ->get();

        $unique = collect();

        foreach ($clients as $client) {
            $emails = $this->parseEmails((string) $client->executive_email);
            foreach ($emails as $email) {
                $normalized = strtolower(trim($email));
                if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                if (! $unique->contains('email', $normalized)) {
                    $unique->push([
                        'email' => $normalized,
                        'name' => $this->extractNameFromEmail($normalized),
                    ]);
                }
            }
        }

        return $unique->sortBy('name')->values()->toArray();
    }

    /**
     * Clientes para filtros (solo los que aparecen en recaudos).
     *
     * @return array<int,array{id:int,client_name:string|null,nit:string|null}>
     */
    public function customers(): array
    {
        return DB::table('clients as c')
            ->join('recaudos as r', 'c.nit', '=', 'r.nit')
            ->select('c.id', 'c.client_name', 'c.nit')
            ->distinct()
            ->orderBy('c.client_name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'client_name' => $row->client_name,
                    'nit' => $row->nit,
                ];
            })
            ->toArray();
    }

    /**
     * Historial de una factura en snapshots de cartera.
     *
     * @return array<int,array{nit:string|null,documento:string,fecha_cartera:string|null,vence:string|null,saldo_contable:float,catera_type:string}>
     */
    public function invoiceHistory(string $documento): array
    {
        $rows = DB::table('cartera')
            ->where('documento', $documento)
            ->select('nit', 'documento', 'fecha_cartera', 'vence', 'saldo_contable', 'catera_type')
            ->orderByDesc('fecha_cartera')
            ->get();

        return $rows->map(function ($row) {
            $fechaCartera = $row->fecha_cartera ? Carbon::parse($row->fecha_cartera)->toDateString() : null;
            $vence = $row->vence ? Carbon::parse($row->vence)->toDateString() : null;

            return [
                'nit' => $row->nit,
                'documento' => (string) $row->documento,
                'fecha_cartera' => $fechaCartera,
                'vence' => $vence,
                'saldo_contable' => round($this->parseNumber($row->saldo_contable), 2),
                'catera_type' => $row->catera_type ?: 'nacional',
            ];
        })->toArray();
    }

    /**
     * @param array{executive_email?:string,client_id?:int,catera_type?:string} $filters
     */
    private function currentDebt(array $filters): float
    {
        $snapshotDate = $this->latestSnapshotDate($filters['catera_type'] ?? null);

        $query = DB::table('recaudos as r')
            ->join('cartera as car', function ($join) use ($snapshotDate) {
                $join->on('r.numero_factura', '=', 'car.documento')
                    ->where('car.fecha_cartera', '=', $snapshotDate);
            })
            ->leftJoin('clients as c', 'r.nit', '=', 'c.nit');

        if (! empty($filters['client_id'])) {
            $query->where('c.id', (int) $filters['client_id']);
        }

        if (! empty($filters['executive_email'])) {
            $email = strtolower(trim($filters['executive_email']));
            $query->where(function ($q) use ($email) {
                $q->whereRaw('FIND_IN_SET(?, REPLACE(LOWER(c.executive_email), " ", "")) > 0', [$email])
                    ->orWhereRaw('JSON_SEARCH(c.executive_email, "one", ?) IS NOT NULL', [$email]);
            });
        }

        if (! empty($filters['catera_type'])) {
            $type = (string) $filters['catera_type'];
            if ($type === 'internacional') {
                $query->where('car.catera_type', '=', 'internacional');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('car.catera_type')->orWhere('car.catera_type', '=', 'nacional');
                });
            }
        }

        return (float) $query->sum('r.valor_cancelado');
    }

    /**
     * @param array{executive_email?:string,client_id?:int,catera_type?:string} $filters
     */
    private function overdueDebt(array $filters): float
    {
        $snapshotDate = $this->latestSnapshotDate($filters['catera_type'] ?? null);
        $today = Carbon::now('America/Bogota')->toDateString();

        $query = DB::table('recaudos as r')
            ->join('cartera as car', function ($join) use ($snapshotDate) {
                $join->on('r.numero_factura', '=', 'car.documento')
                    ->where('car.fecha_cartera', '=', $snapshotDate);
            })
            ->leftJoin('clients as c', 'r.nit', '=', 'c.nit')
            ->where('r.fecha_vencimiento', '<', $today);

        if (! empty($filters['client_id'])) {
            $query->where('c.id', (int) $filters['client_id']);
        }

        if (! empty($filters['executive_email'])) {
            $email = strtolower(trim($filters['executive_email']));
            $query->where(function ($q) use ($email) {
                $q->whereRaw('FIND_IN_SET(?, REPLACE(LOWER(c.executive_email), " ", "")) > 0', [$email])
                    ->orWhereRaw('JSON_SEARCH(c.executive_email, "one", ?) IS NOT NULL', [$email]);
            });
        }

        if (! empty($filters['catera_type'])) {
            $type = (string) $filters['catera_type'];
            if ($type === 'internacional') {
                $query->where('car.catera_type', '=', 'internacional');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('car.catera_type')->orWhere('car.catera_type', '=', 'nacional');
                });
            }
        }

        return (float) $query->sum('r.valor_cancelado');
    }

    /**
     * Proyecciรณn de recaudo desde parciales: despacho + 15.
     *
     * @param array{executive_email?:string,client_id?:int,catera_type?:string} $filters
     */
    private function projectedFromPartials(Carbon $from, Carbon $to, array $filters): float
    {
        $start = $from->toDateString();
        $end = $to->toDateString();

        $query = DB::table('partials as p')
            ->join('products as prod', 'p.product_id', '=', 'prod.id')
            ->join('purchase_orders as po', 'p.order_id', '=', 'po.id')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->where('p.type', '=', 'real')
            ->whereRaw('DATE_ADD(p.dispatch_date, INTERVAL 15 DAY) BETWEEN ? AND ?', [$start, $end])
            ->selectRaw('SUM(p.quantity * prod.price * COALESCE(p.trm, 1)) as total_projected');

        if (! empty($filters['client_id'])) {
            $query->where('c.id', (int) $filters['client_id']);
        }

        if (! empty($filters['executive_email'])) {
            $email = strtolower(trim($filters['executive_email']));
            $query->where(function ($q) use ($email) {
                $q->whereRaw('FIND_IN_SET(?, REPLACE(LOWER(c.executive_email), " ", "")) > 0', [$email])
                    ->orWhereRaw('JSON_SEARCH(c.executive_email, "one", ?) IS NOT NULL', [$email]);
            });
        }

        $row = $query->first();
        return (float) ($row->total_projected ?? 0);
    }

    private function latestSnapshotDate(?string $cateraType): string
    {
        $query = DB::table('cartera');

        if ($cateraType === 'internacional') {
            $query->where('catera_type', '=', 'internacional');
        } elseif ($cateraType === 'nacional') {
            $query->where(function ($q) {
                $q->whereNull('catera_type')->orWhere('catera_type', '=', 'nacional');
            });
        }

        return (string) ($query->max('fecha_cartera') ?? Carbon::now('America/Bogota')->toDateString());
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $rows
     * @return array<int,array<string,mixed>>
     */
    private function mapClientRows(Collection $rows, string $today): Collection
    {
        return $rows->map(function ($row) use ($today) {
            $estadoPagado = 'PAGADO';

            if ($row->numero_factura !== null && (float) ($row->current_debt ?? 0) > 0) {
                if ($row->fecha_vencimiento && $row->fecha_vencimiento < $today) {
                    $estadoPagado = 'EN MORA';
                } else {
                    $estadoPagado = 'PENDIENTE';
                }
            }

            return [
                'client_id' => (int) ($row->client_id ?? 0),
                'client_name' => $row->client_name ?? 'Cliente sin nombre',
                'nit' => $row->nit,
                'numero_factura' => $row->numero_factura,
                'fecha_recaudo' => $row->fecha_recaudo ? Carbon::parse($row->fecha_recaudo)->toDateString() : null,
                'fecha_vencimiento' => $row->fecha_vencimiento ? Carbon::parse($row->fecha_vencimiento)->toDateString() : null,
                'valor_cancelado' => round((float) ($row->valor_cancelado ?? 0), 2),
                'current_debt' => round((float) ($row->current_debt ?? 0), 2),
                'overdue_amount' => round((float) ($row->overdue_amount ?? 0), 2),
                'estado_pagado' => $estadoPagado,
                'catera_type' => $row->catera_type,
            ];
        });
    }

    private function extractNameFromEmail(string $email): string
    {
        $name = explode('@', $email)[0] ?? $email;
        $name = str_replace(['.', '_', '-'], ' ', $name);
        return ucwords($name);
    }

    /**
     * @return array<int,string>
     */
    private function parseEmails(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function parseNumber(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return 0.0;
        }

        // Common pattern in this DB: "." thousands and "," decimals.
        if (str_contains($stringValue, ',') && str_contains($stringValue, '.')) {
            $stringValue = str_replace('.', '', $stringValue);
            $stringValue = str_replace(',', '.', $stringValue);
        } elseif (str_contains($stringValue, ',')) {
            $stringValue = str_replace(',', '.', $stringValue);
        }

        $stringValue = preg_replace('/[^0-9.\-]/', '', $stringValue) ?? $stringValue;
        return (float) $stringValue;
    }
}

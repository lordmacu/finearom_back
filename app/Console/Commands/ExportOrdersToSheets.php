<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;

class ExportOrdersToSheets extends Command
{
    protected $signature = 'sheets:export-orders
                            {--from= : Fecha inicial (Y-m-d), por defecto inicio del mes actual}
                            {--to=   : Fecha final (Y-m-d), por defecto hoy}
                            {--all   : Exportar todas las órdenes históricas (completed + parcial_status)}';

    protected $description = 'Exporta órdenes despachadas a Google Sheets (backfill o rango de fechas)';

    public function __construct(private readonly GoogleSheetsService $sheetsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sheetsUserId = $this->sheetsService->getAnyUserWithSheetsAccess();

        if (!$sheetsUserId) {
            $this->error('Ningún usuario tiene Google Sheets conectado. Conecta primero desde Ajustes > Google.');
            return Command::FAILURE;
        }

        $query = PurchaseOrder::with(['client', 'products', 'branchOffice', 'project'])
            ->whereIn('status', ['completed', 'parcial_status']);

        if ($this->option('all')) {
            $this->info('Exportando TODAS las órdenes históricas...');
        } else {
            $from = $this->option('from') ?? now()->startOfMonth()->toDateString();
            $to   = $this->option('to')   ?? now()->toDateString();
            $query->whereBetween('dispatch_date', [$from, $to]);
            $this->info("Exportando órdenes del {$from} al {$to}...");
        }

        $orders = $query->orderBy('dispatch_date')->get();

        if ($orders->isEmpty()) {
            $this->warn('No hay órdenes para exportar en ese rango.');
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$orders->count()} órdenes. Exportando...");
        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        $errors = 0;
        foreach ($orders as $order) {
            try {
                $this->sheetsService->appendOrderRow($sheetsUserId, $order);
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->warn("Error en OC {$order->order_consecutive}: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $exported = $orders->count() - $errors;
        $this->info("Exportadas: {$exported} | Errores: {$errors}");

        return Command::SUCCESS;
    }
}

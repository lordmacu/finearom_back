<?php

// app/Exports/PlannedStatsExport.php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Maatwebsite\Excel\Events\AfterSheet;
use DB;

class PlannedStatsExport implements FromCollection, WithHeadings, WithStyles, WithColumnFormatting, ShouldAutoSize, WithEvents
{
    private $startDate;
    private $endDate;
    private $detailedData;

    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function collection()
    {
        $this->detailedData = $this->getDetailedPlannedStatsForExport();
        
        return collect($this->detailedData['details'])->map(function ($item) {
            return [
                'order_id' => $item['order_id'],
                'product_order_id' => $item['product_order_id'],
                'client_name' => $item['client_name'],
                'product_name' => $item['product_name'],
                'product_sku' => $item['product_sku'],
                'quantity' => $item['quantity'],
                'is_sample' => $item['is_sample'] ? 'Sí' : 'No',
                'price_usd' => $item['price_usd'],
                'order_creation_date' => $item['order_creation_date'],
                'planned_dispatch_date' => $item['planned_dispatch_date'],
                'dispatch_source' => $item['dispatch_source'],
                'trm_used' => $item['trm_used'],
                'trm_source' => $item['trm_source'],
                'value_usd' => $item['value_usd'],
                'value_cop' => $item['value_cop'],
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID Orden',
            'ID Producto Orden',
            'Cliente',
            'Producto',
            'SKU',
            'Cantidad',
            'Es Muestra',
            'Precio USD',
            'Fecha Creación Orden',
            'Fecha Despacho Planeada',
            'Origen Fecha Despacho',
            'TRM Utilizada',
            'Origen TRM',
            'Valor USD',
            'Valor COP'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Estilo para los headers
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF']
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ]
            ]
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => '#,##0.00',    // Price USD
            'I' => 'yyyy-mm-dd',  // Order creation date
            'J' => 'yyyy-mm-dd',  // Planned dispatch date
            'L' => '#,##0.00',    // TRM
            'N' => '#,##0.00',    // Value USD
            'O' => '#,##0.00',    // Value COP
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                
                // Aplicar colores según el origen del dispatch
                for ($row = 2; $row <= $highestRow; $row++) {
                    $dispatchSource = $sheet->getCell('K' . $row)->getValue();
                    $fillColor = $this->getDispatchSourceColor($dispatchSource);
                    
                    if ($fillColor) {
                        $sheet->getStyle('A' . $row . ':O' . $row)->applyFromArray([
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['rgb' => $fillColor]
                            ]
                        ]);
                    }
                }
                
                // Añadir fila de totales
                $totalRow = $highestRow + 2;
                $sheet->setCellValue('A' . $totalRow, 'TOTALES');
                $sheet->setCellValue('F' . $totalRow, $this->detailedData['summary']['products_count']);
                $sheet->setCellValue('N' . $totalRow, $this->detailedData['summary']['total_value_usd']);
                $sheet->setCellValue('O' . $totalRow, $this->detailedData['summary']['total_value_cop']);
                
                // Estilo para totales
                $sheet->getStyle('A' . $totalRow . ':O' . $totalRow)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E6E6E6']
                    ]
                ]);
                
                // Añadir resumen de estadísticas
                $this->addSummarySection($sheet, $totalRow + 2);
                
                // Añadir leyenda de colores
                $this->addColorLegend($sheet, $totalRow + 8);
            }
        ];
    }

    private function getDetailedPlannedStatsForExport()
    {
        // Obtener todos los productos de órdenes y sus partials
        $orderProducts = DB::table('purchase_order_product')
            ->join('purchase_orders', 'purchase_order_product.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id')
            ->leftJoin('partials', function($join) {
                $join->on('partials.product_order_id', '=', 'purchase_order_product.id')
                     ->whereIn('partials.type', ['real', 'temporal']);
            })
            ->select(
                'purchase_orders.id as order_id',
                'purchase_orders.order_creation_date',
                'purchase_orders.trm as order_trm',
                'purchase_order_product.id as product_order_id',
                'purchase_order_product.quantity',
                'purchase_order_product.muestra',
                'purchase_order_product.price as order_product_price',
                'products.product_name as product_name',
                'products.code as product_sku',
                'products.price as product_price',
                'clients.client_name as client_name',
                'partials.dispatch_date as partial_dispatch_date',
                'partials.type as partial_type',
                'partials.trm as partial_trm'
            )
            ->get();

        // Obtener TRMs
        $expandedEndDate = $this->addBusinessDays($this->endDate, 15);
        $trmData = DB::table('trm_daily')
            ->whereBetween('date', [$this->startDate, $expandedEndDate])
            ->pluck('value', 'date')
            ->toArray();

        // Procesar datos
        $productOrdersGrouped = $orderProducts->groupBy('product_order_id');
        $details = [];
        $totalValueUsd = 0;
        $totalValueCop = 0;
        $ordersSet = [];
        $totalProducts = 0;
        $trmUsageStats = ['from_partial_real' => 0, 'from_table' => 0, 'from_order' => 0, 'default' => 0];
        $dispatchSourceStats = ['from_partial_real' => 0, 'from_partial_temporal' => 0, 'calculated_business_days' => 0];

        foreach ($productOrdersGrouped as $productOrderId => $productData) {
            $firstProduct = $productData->first();
            $orderQuantity = (int) $firstProduct->quantity;
            $isSample = (int) $firstProduct->muestra === 1;
            
            // Obtener fecha de despacho y fuente
            $dispatchInfo = $this->getPlannedDispatchDateWithSource(
                $productData, 
                $firstProduct->order_creation_date,
                $dispatchSourceStats
            );
            
            $plannedDispatchDate = $dispatchInfo['date'];
            $dispatchSource = $this->getDispatchSourceLabel($dispatchInfo['source_type']);
            $partialTrm = $dispatchInfo['partial_trm'] ?? null;

            // Verificar rango de fechas
            if ($plannedDispatchDate < $this->startDate || $plannedDispatchDate > $this->endDate) {
                continue;
            }

            // Usar precio efectivo: si order_product_price > 0, usar ese, sino usar product_price
            $effectivePrice = ($firstProduct->order_product_price > 0) ? $firstProduct->order_product_price : ($firstProduct->product_price ?? 0);
            $priceUsd = (float) $effectivePrice;
            
            // Obtener TRM usando la función exacta de tu controlador
            $trmUsed = $this->getTrmForDateWithPartial(
                $plannedDispatchDate,
                $partialTrm,
                $firstProduct->order_trm,
                $trmData,
                $dispatchInfo['source_type'],
                $trmUsageStats
            );

            $trmSource = $this->getTrmSourceLabel(
                $dispatchInfo['source_type'],
                $partialTrm,
                $trmData,
                $plannedDispatchDate,
                $firstProduct->order_trm
            );

            $valueUsd = $isSample ? 0 : $priceUsd * $orderQuantity;
            $valueCop = $valueUsd * $trmUsed;
             $details[] = [
                'order_id' => $firstProduct->order_id,
                'product_order_id' => $productOrderId,
                'client_name' => $firstProduct->client_name,
                'product_name' => $firstProduct->product_name,
                'product_sku' => $firstProduct->product_sku,
                'quantity' => $orderQuantity,
                'is_sample' => $isSample,
                'price_usd' => $priceUsd,
                'order_creation_date' => $firstProduct->order_creation_date,
                'planned_dispatch_date' => $plannedDispatchDate,
                'dispatch_source' => $dispatchSource,
                'trm_used' => $trmUsed,
                'trm_source' => $trmSource,
                'value_usd' => $valueUsd,
                'value_cop' => $valueCop,
            ];

            if (!$isSample) {
                $totalValueUsd += $valueUsd;
                $totalValueCop += $valueCop;
            }
            $totalProducts += $orderQuantity;
            $ordersSet[$firstProduct->order_id] = true;
        }

        return [
            'details' => $details,
            'summary' => [
                'total_value_usd' => $totalValueUsd,
                'total_value_cop' => $totalValueCop,
                'orders_count' => count($ordersSet),
                'products_count' => $totalProducts,
                'trm_usage_stats' => $trmUsageStats,
                'dispatch_source_stats' => $dispatchSourceStats,
            ]
        ];
    }

    /**
     * Determina la fecha de despacho planeada con nueva lógica de prioridad:
     * 1. Partials 'real' con dispatch_date (máxima prioridad)
     * 2. Partials 'temporal' con dispatch_date
     * 3. Order creation date + 10 días hábiles (fallback)
     * (FUNCIÓN EXACTA de tu controlador)
     */
    private function getPlannedDispatchDateWithSource($productData, $orderCreationDate, &$dispatchSourceStats)
    {
        // Separar partials por tipo y filtrar los que tienen dispatch_date
        $realPartials = $productData->where('partial_type', 'real')
                                    ->whereNotNull('partial_dispatch_date');
        
        $temporalPartials = $productData->where('partial_type', 'temporal')
                                       ->whereNotNull('partial_dispatch_date');
        
        // 1. PRIORIDAD MÁXIMA: Partials tipo 'real' con dispatch_date
        if ($realPartials->isNotEmpty()) {
            $earliestReal = $realPartials->sortBy('partial_dispatch_date')->first();
            $dispatchSourceStats['from_partial_real']++;
            
            return [
                'date' => $earliestReal->partial_dispatch_date,
                'source_type' => 'partial_real',
                'partial_trm' => $earliestReal->partial_trm // Incluir TRM del partial
            ];
        }
        
        // 2. SEGUNDA PRIORIDAD: Partials tipo 'temporal' con dispatch_date
        if ($temporalPartials->isNotEmpty()) {
            $earliestTemporal = $temporalPartials->sortBy('partial_dispatch_date')->first();
            $dispatchSourceStats['from_partial_temporal']++;
            
            return [
                'date' => $earliestTemporal->partial_dispatch_date,
                'source_type' => 'partial_temporal'
            ];
        }
        
        // 3. FALLBACK: Calcular order_creation_date + 10 días hábiles
        $plannedDate = $this->addBusinessDays($orderCreationDate, 10);
        $dispatchSourceStats['calculated_business_days']++;
        
        return [
            'date' => $plannedDate,
            'source_type' => 'calculated_business_days'
        ];
    }

    /**
     * Obtiene la TRM considerando si viene de partial real o usando la lógica anterior
     * (FUNCIÓN EXACTA de tu controlador)
     */
    private function getTrmForDateWithPartial($date, $partialTrm, $orderTrm, $trmData, $sourceType, &$trmUsageStats)
    {
        // 1. PRIORIDAD MÁXIMA: Si viene de partial real y tiene TRM válida, usar esa
        if ($sourceType === 'partial_real' && !empty($partialTrm) && $partialTrm > 3800) {
            $trmUsageStats['from_partial_real']++;
            return (float) $partialTrm;
        }
        
        // 2. Prioridad: TRM de la tabla trm_daily para la fecha específica
        if (isset($trmData[$date])) {
            $trmUsageStats['from_table']++;
            return (float) $trmData[$date];
        }
        
        // 3. Fallback: TRM de la orden si existe y es válida
        if (!empty($orderTrm) && $orderTrm > 3800) {
            $trmUsageStats['from_order']++;
            return (float) $orderTrm;
        }
        
        // 4. Último fallback: TRM por defecto
        $trmUsageStats['default']++;
        return 4000.0;
    }

    private function getTrmSourceLabel($sourceType, $partialTrm, $trmData, $date, $orderTrm)
    {
        if ($sourceType === 'partial_real' && !empty($partialTrm) && $partialTrm > 3800) {
            return 'Partial Real';
        }
        
        if (isset($trmData[$date])) {
            return 'Tabla TRM';
        }
        
        if (!empty($orderTrm) && $orderTrm > 3800) {
            return 'Orden';
        }
        
        return 'Por Defecto';
    }

    /**
     * Agregar días hábiles a una fecha usando Carbon (como en tu controlador)
     */
    private function addBusinessDays($date, $businessDays)
    {
        $currentDate = new \DateTime($date);
        $addedDays = 0;
        
        while ($addedDays < $businessDays) {
            $currentDate->add(new \DateInterval('P1D'));
            
            // Si no es sábado (6) ni domingo (0), contar como día hábil
            if (!in_array($currentDate->format('w'), [6, 0])) {
                $addedDays++;
            }
        }
        
        return $currentDate->format('Y-m-d');
    }

    private function getDispatchSourceColor($dispatchSource)
    {
        $colors = [
            'Partial Real' => 'C5E1A5',      // Verde claro
            'Partial Temporal' => 'FFE082',   // Amarillo claro  
            'Calculado (10 días hábiles)' => 'FFCDD2' // Rosa claro
        ];
        
        return $colors[$dispatchSource] ?? null;
    }

    private function getDispatchSourceLabel($sourceType)
    {
        $labels = [
            'partial_real' => 'Partial Real',
            'partial_temporal' => 'Partial Temporal',
            'calculated_business_days' => 'Calculado (10 días hábiles)'
        ];
        
        return $labels[$sourceType] ?? 'Desconocido';
    }

    private function addSummarySection($sheet, $startRow)
    {
        $summary = $this->detailedData['summary'];
        
        $sheet->setCellValue('A' . $startRow, 'RESUMEN DE ESTADÍSTICAS');
        $sheet->getStyle('A' . $startRow)->getFont()->setBold(true);
        
        $startRow += 2;
        
        // Estadísticas de origen de fecha de despacho
        $sheet->setCellValue('A' . $startRow, 'Origen Fecha Despacho:');
        $sheet->setCellValue('B' . ($startRow + 1), 'Partial Real: ' . $summary['dispatch_source_stats']['from_partial_real']);
        $sheet->setCellValue('B' . ($startRow + 2), 'Partial Temporal: ' . $summary['dispatch_source_stats']['from_partial_temporal']);
        $sheet->setCellValue('B' . ($startRow + 3), 'Calculado: ' . $summary['dispatch_source_stats']['calculated_business_days']);
        
        $startRow += 5;
        
        // Estadísticas de origen de TRM
        $sheet->setCellValue('A' . $startRow, 'Origen TRM:');
        $sheet->setCellValue('B' . ($startRow + 1), 'Partial Real: ' . $summary['trm_usage_stats']['from_partial_real']);
        $sheet->setCellValue('B' . ($startRow + 2), 'Tabla TRM: ' . $summary['trm_usage_stats']['from_table']);
        $sheet->setCellValue('B' . ($startRow + 3), 'Orden: ' . $summary['trm_usage_stats']['from_order']);
        $sheet->setCellValue('B' . ($startRow + 4), 'Por Defecto: ' . $summary['trm_usage_stats']['default']);
    }

    private function addColorLegend($sheet, $startRow)
    {
        $sheet->setCellValue('A' . $startRow, 'LEYENDA DE COLORES');
        $sheet->getStyle('A' . $startRow)->getFont()->setBold(true);
        
        $legends = [
            ['color' => 'C5E1A5', 'text' => 'Verde: Partial Real'],
            ['color' => 'FFE082', 'text' => 'Amarillo: Partial Temporal'],
            ['color' => 'FFCDD2', 'text' => 'Rosa: Calculado (10 días hábiles)']
        ];
        
        foreach ($legends as $i => $legend) {
            $row = $startRow + 2 + $i;
            $sheet->setCellValue('A' . $row, $legend['text']);
            $sheet->getStyle('A' . $row)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $legend['color']]
                ]
            ]);
        }
    }
}
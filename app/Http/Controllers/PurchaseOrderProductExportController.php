<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PurchaseOrderProduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderProductExportController extends Controller
{
    public function export(Request $request)
    {
        $clientId = $request->query('client_id');
        $consecutive = $request->query('consecutive');
        $newWin = $request->query('new_win');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        // Validar rango si viene
        if ($dateFrom && $dateTo) {
            $start = Carbon::parse($dateFrom)->startOfDay();
            $end = Carbon::parse($dateTo)->endOfDay();
            if ($start->diffInMonths($end) > 3) {
                return response()->json(['success' => false, 'message' => 'El rango de fechas no puede exceder los 3 meses'], 422);
            }
        } else {
            $start = $end = null;
        }

        $query = PurchaseOrderProduct::select([
            'purchase_order_product.*',
            'purchase_orders.order_consecutive',
            'purchase_orders.order_creation_date',
            'purchase_orders.required_delivery_date',
            'purchase_orders.status as order_status',
            'products.code as product_code',
            'products.product_name',
            // Precio efectivo: 0 si muestra, sino pivot > 0, sino products.price
            \DB::raw('CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                      END as price_product'),
            'clients.client_name',
            'clients.nit',
            // Subconsulta fecha despacho real
            \DB::raw('(SELECT dispatch_date FROM partials
                      WHERE partials.order_id = purchase_orders.id
                      AND partials.type = "real"
                      ORDER BY partials.created_at DESC
                      LIMIT 1) as real_dispatch_date'),
            // Subconsulta fecha despacho temporal
            \DB::raw('(SELECT dispatch_date FROM partials
                      WHERE partials.order_id = purchase_orders.id
                      AND partials.type = "temporal"
                      ORDER BY partials.created_at DESC
                      LIMIT 1) as temporal_dispatch_date')
        ])
            ->join('purchase_orders', 'purchase_order_product.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id');

        if ($clientId && $clientId !== 'null') {
            $query->where('purchase_orders.client_id', $clientId);
        }

        if ($consecutive && $consecutive !== 'null') {
            $query->where('purchase_orders.order_consecutive', 'LIKE', '%' . $consecutive . '%');
        }

        if ($request->has('new_win') && ($newWin === '1' || $newWin === '0')) {
            $query->where('purchase_order_product.new_win', $newWin);
        }

        $products = $query->get()->map(function ($item) {
            $effectiveDispatchDate = $item->real_dispatch_date ?? $item->temporal_dispatch_date ?? $item->delivery_date;
            $item->effective_dispatch_date = $effectiveDispatchDate;
            return $item;
        });

        if ($start && $end) {
            $products = $products->filter(function ($item) use ($start, $end) {
                if (! $item->effective_dispatch_date) {
                    return false;
                }
                $dispatchDate = Carbon::parse($item->effective_dispatch_date);
                return $dispatchDate->between($start, $end);
            });
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'A1' => 'Consecutivo Orden',
            'B1' => 'Cliente',
            'C1' => 'NIT',
            'D1' => 'Fecha Creación Orden',
            'E1' => 'Estado Orden',
            'F1' => 'Código Producto',
            'G1' => 'Nombre Producto',
            'H1' => 'Cantidad',
            'I1' => 'Precio Unitario',
            'J1' => 'Subtotal',
            'K1' => 'New Win',
            'L1' => 'Fecha Despacho Efectiva',
            'M1' => 'Origen Fecha Despacho'
        ];

        foreach ($headers as $cell => $header) {
            $sheet->setCellValue($cell, $header);
        }

        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->getStyle('A1:M1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');

        $row = 2;
        foreach ($products as $product) {
            $dateOrigin = '';
            if ($product->real_dispatch_date) {
                $dateOrigin = 'Parcial Real';
            } elseif ($product->temporal_dispatch_date) {
                $dateOrigin = 'Parcial Temporal';
            } elseif ($product->delivery_date) {
                $dateOrigin = 'Producto';
            } else {
                $dateOrigin = 'Sin fecha';
            }

            $subtotal = ($product->quantity ?? 0) * ($product->price_product ?? 0);

            $sheet->setCellValue('A' . $row, $product->order_consecutive);
            $sheet->setCellValue('B' . $row, $product->client_name);
            $sheet->setCellValue('C' . $row, $product->nit);
            $sheet->setCellValue('D' . $row, $product->order_creation_date);
            $sheet->setCellValue('E' . $row, $product->order_status);
            $sheet->setCellValue('F' . $row, $product->product_code);
            $sheet->setCellValue('G' . $row, $product->product_name);
            $sheet->setCellValue('H' . $row, $product->quantity);
            $sheet->setCellValue('I' . $row, $product->price_product);
            $sheet->setCellValue('J' . $row, $subtotal);
            $sheet->setCellValue('K' . $row, $product->new_win ? 'Sí' : 'No');
            $sheet->setCellValue('L' . $row, $product->effective_dispatch_date);
            $sheet->setCellValue('M' . $row, $dateOrigin);

            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = 'productos_ordenes_' . now()->format('Ymd_His') . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment;filename=\"{$fileName}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderExportController extends Controller
{
    /**
     * Exporta órdenes de compra a Excel.
     * Respeta el rango de fechas (creación) y limita a 3 meses como en el legacy.
     */
    public function export(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if (! $startDate || ! $endDate) {
            return response()->json(['success' => false, 'message' => 'Debe proporcionar un rango de fechas válido'], 422);
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Limitar rango a 3 meses (legacy)
        if ($start->diffInMonths($end) > 3) {
            return response()->json(['success' => false, 'message' => 'El rango de fechas no puede exceder los 3 meses'], 422);
        }

        $clientId = $request->query('client_id');
        $status = $request->query('status');
        $orderConsecutive = $request->query('order_consecutive');

        $query = PurchaseOrder::query()
            ->with(['client:id,client_name,nit', 'products'])
            ->whereBetween('order_creation_date', [$start->toDateString(), $end->toDateString()]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        if ($status) {
            $query->where('status', $status);
        }
        if ($orderConsecutive) {
            $query->where('order_consecutive', 'like', '%' . $orderConsecutive . '%');
        }

        $orders = $query->get()->map(function (PurchaseOrder $order) {
            $total = $order->products->reduce(function ($carry, $item) {
                // Usar precio efectivo: 0 si muestra, sino pivot > 0, sino product->price
                $effectivePrice = ($item->pivot->muestra == 1)
                    ? 0
                    : (($item->pivot->price > 0) ? $item->pivot->price : ($item->price ?? 0));
                return $carry + ($effectivePrice * ($item->pivot->quantity ?? 0));
            }, 0);

            return [
                'created_at' => $order->order_creation_date,
                'consecutive' => $order->order_consecutive,
                'client' => optional($order->client)->client_name,
                'nit' => optional($order->client)->nit,
                'total' => $total,
                'status' => $order->status,
            ];
        });

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'A1' => 'Fecha creación',
            'B1' => 'Consecutivo',
            'C1' => 'Cliente',
            'D1' => 'NIT',
            'E1' => 'Total',
            'F1' => 'Estado',
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }

        $row = 2;
        foreach ($orders as $order) {
            $sheet->setCellValue('A' . $row, $order['created_at']);
            $sheet->setCellValue('B' . $row, $order['consecutive']);
            $sheet->setCellValue('C' . $row, $order['client']);
            $sheet->setCellValue('D' . $row, $order['nit']);
            $sheet->setCellValue('E' . $row, $order['total']);
            $sheet->setCellValue('F' . $row, $order['status']);
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = 'ordenes_compra_' . now()->format('Ymd_His') . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment;filename=\"{$fileName}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}

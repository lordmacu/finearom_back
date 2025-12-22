<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderProduct;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PurchaseOrderImportController extends Controller
{
    /**
     * Importa órdenes de compra desde un Excel/CSV.
     * Encabezados esperados (case-insensitive):
     *  - orden_de_compra
     *  - nit
     *  - fecha_de_despacho
     *  - fecha_de_solicitud
     *  - codigo
     *  - cantidad
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt'],
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            return response()->json(['success' => false, 'message' => 'Archivo sin datos'], 422);
        }

        // Headers
        $headers = array_map(function ($val) {
            return strtolower(trim((string) $val));
        }, $rows[1] ?? []);

        $colIndex = array_flip($headers);
        $required = ['orden_de_compra', 'nit', 'fecha_de_despacho', 'fecha_de_solicitud', 'codigo', 'cantidad'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $colIndex)) {
                return response()->json(['success' => false, 'message' => "Falta columna requerida: {$key}"], 422);
            }
        }

        // Agrupar por orden_de_compra (desde fila 2)
        $ordersData = [];
        for ($i = 2; $i <= count($rows); $i++) {
            $row = $rows[$i] ?? [];
            if (! is_array($row)) {
                continue;
            }
            $consecutive = trim((string) ($row[$colIndex['orden_de_compra'] + 1] ?? ''));
            if ($consecutive === '') {
                continue;
            }
            $ordersData[$consecutive][] = $row;
        }

        $createdOrders = 0;
        $createdProducts = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($ordersData as $consecutive => $rowsForOrder) {
                $first = $rowsForOrder[0];

                $nit = trim((string) ($first[$colIndex['nit'] + 1] ?? ''));
                $client = Client::query()->where('nit', $nit)->first();
                if (! $client) {
                    $errors[] = "Cliente con NIT {$nit} no encontrado (OC {$consecutive})";
                    continue;
                }

                $fechaDespacho = $this->parseDate($first[$colIndex['fecha_de_despacho'] + 1] ?? null);
                $fechaSolicitud = $this->parseDate($first[$colIndex['fecha_de_solicitud'] + 1] ?? null);

                $order = PurchaseOrder::query()->firstOrCreate(
                    ['order_consecutive' => $consecutive],
                    [
                        'client_id' => $client->id,
                        'branch_office_id' => null,
                        'order_creation_date' => $fechaSolicitud,
                        'required_delivery_date' => $fechaDespacho,
                        'status' => 'pending',
                        'trm' => 0,
                        'delivery_address' => $client->address ?? '',
                    ]
                );

                if ($order->wasRecentlyCreated) {
                    $createdOrders++;
                }

                foreach ($rowsForOrder as $row) {
                    $code = trim((string) ($row[$colIndex['codigo'] + 1] ?? ''));
                    $quantity = (float) ($row[$colIndex['cantidad'] + 1] ?? 0);

                    if ($code === '' || $quantity <= 0) {
                        $errors[] = "Producto/cantidad inválida en OC {$consecutive}";
                        continue;
                    }

                    $product = Product::query()
                        ->where('code', $code)
                        ->where('client_id', $client->id)
                        ->first();

                    if (! $product) {
                        $product = Product::query()->create([
                            'product_name' => $code,
                            'code' => $code,
                            'price' => 0,
                            'client_id' => $client->id,
                        ]);
                        $createdProducts++;
                    }

                    PurchaseOrderProduct::query()->create([
                        'purchase_order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'price' => $product->price ?? 0,
                        'quantity' => $quantity,
                        'branch_office_id' => null,
                        'delivery_date' => $fechaDespacho,
                        'new_win' => $row[$colIndex['new_win'] + 1] ?? 0,
                        'muestra' => $row[$colIndex['muestra'] + 1] ?? 0,
                    ]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al importar: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Importación completada',
            'created_orders' => $createdOrders,
            'created_products' => $createdProducts,
            'errors' => $errors,
        ]);
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            $date = Carbon::parse($value);
            return $date->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}

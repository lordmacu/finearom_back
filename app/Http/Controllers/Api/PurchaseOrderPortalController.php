<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchOffice;
use App\Models\Client;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\TrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderPortalController extends Controller
{
    public function __construct(private readonly TrmService $trmService)
    {
    }

    public function metadata(Request $request)
    {
        $client = Client::where('user_id', $request->user()->id)->first();
        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $branchOffices = BranchOffice::where('client_id', $client->id)
            ->orderBy('id')
            ->get();

        $products = Product::where('client_id', $client->id)
            ->orderBy('id')
            ->get();

        $exchange = $this->getExchangeRate();

        return response()->json([
            'client' => $client,
            'branch_offices' => $branchOffices,
            'products' => $products,
            'exchange' => $exchange,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_office_id' => ['required', 'exists:branch_offices,id'],
            'required_delivery_date' => ['required', 'date', 'after:today'],
            'order_consecutive' => ['required', 'string', 'unique:purchase_orders,order_consecutive'],
            'delivery_address' => ['required', 'string'],
            'observations' => ['nullable', 'string'],
            'products' => ['required', 'array'],
            'products.*.product_id' => ['required', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $client = Client::where('user_id', $request->user()->id)->first();
        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $branchOffice = BranchOffice::where('client_id', $client->id)
            ->where('id', $validated['branch_office_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($validated, $client, $branchOffice) {
            $purchaseOrder = new PurchaseOrder();
            $purchaseOrder->client_id = $client->id;
            $purchaseOrder->branch_office_id = $branchOffice->id;
            $purchaseOrder->order_creation_date = now();
            $purchaseOrder->required_delivery_date = $validated['required_delivery_date'];
            $purchaseOrder->order_consecutive = $validated['order_consecutive'];
            $purchaseOrder->delivery_address = $validated['delivery_address'];
            $purchaseOrder->observations = $validated['observations'] ?? null;
            $purchaseOrder->status = 'pending';
            $purchaseOrder->save();

            foreach ($validated['products'] as $productData) {
                $product = Product::where('client_id', $client->id)
                    ->findOrFail($productData['product_id']);

                $purchaseOrder->products()->attach($product->id, [
                    'quantity' => $productData['quantity'],
                    'price' => $product->price ?? 0,
                    'branch_office_id' => $branchOffice->id,
                ]);
            }

            $purchaseOrder->load(['client', 'products', 'comments']);
            $pdfContent = $this->generatePdfOrder($purchaseOrder);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="invoice.pdf"');
        });
    }

    private function getExchangeRate(): float
    {
        try {
            $trm = $this->trmService->getTrm();
            if ($trm <= 0) {
                return 0.0;
            }
            return 1 / $trm;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function generatePdfOrder(PurchaseOrder $purchaseOrder): string
    {
        $logo = $this->getLogoBase64();

        $pdf = \PDF::loadView('pdf.purchase_order', compact('purchaseOrder', 'logo'));

        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'defaultFont' => 'Arial',
        ]);

        return $pdf->output();
    }

    private function getLogoBase64(): string
    {
        $logoPath = public_path('images/logo.png');

        if (! file_exists($logoPath)) {
            return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        }

        $imageData = base64_encode(file_get_contents($logoPath));

        return 'data:image/png;base64,' . $imageData;
    }
}

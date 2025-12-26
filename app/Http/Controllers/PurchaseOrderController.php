<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Partial;
use App\Models\Process;
use App\Models\PurchaseOrderProduct;
use App\Rules\UniqueOrderConsecutiveForClient;
use App\Services\TrmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Mail\PurchaseOrderObservationMail;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PurchaseOrderController extends Controller
{
    protected $trmService;

    public function __construct(TrmService $trmService)
    {
        $this->trmService = $trmService;
        $this->middleware('can:purchase_order list')->only(['index']);
    }

    /**
    * Lista paginada de órdenes de compra con filtros básicos.
    */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()
            ->with([
                'client:id,client_name,nit,email',
                'branchOffice:id,name',
                'products:id,product_name,code,price',
                'partials',
            ]);

        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', (int) $clientId);
        }

        $creationRange = $this->parseRange($request->query('creation_date'));
        if ($creationRange) {
            $query->whereBetween('order_creation_date', $creationRange);
        }

        $deliveryRange = $this->parseRange($request->query('delivery_date'));
        if ($deliveryRange) {
            $query->whereBetween('required_delivery_date', $deliveryRange);
        }

        if ($consecutive = $request->query('order_consecutive')) {
            $query->where('order_consecutive', 'like', '%' . $consecutive . '%');
        }

        if ($status = $request->query('status')) {
            // Aceptar alias de legacy 'partial_status' -> 'parcial_status'
            $normalizedStatus = $status === 'partial_status' ? 'parcial_status' : $status;
            $query->where('status', $normalizedStatus);
        }

        $query->selectRaw('purchase_orders.*, (
            SELECT SUM(
                CASE
                    WHEN pop.price > 0 THEN pop.price * pop.quantity
                    ELSE products.price * pop.quantity
                END
            )
            FROM purchase_order_product pop
            JOIN products ON products.id = pop.product_id
            WHERE pop.purchase_order_id = purchase_orders.id
        ) as total_sum');

        $allowedSorts = ['id', 'order_creation_date', 'required_delivery_date', 'order_consecutive', 'status', 'total'];
        $sortBy = $request->query('sort_by', 'id');
        $sortDir = strtolower($request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }
        if ($sortBy === 'total') {
            $query->orderBy('total_sum', $sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        $paginate = filter_var($request->query('paginate', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min(200, $perPage));

        if ($paginate === false) {
            $items = $query->limit($perPage)->get();
            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'total' => $items->count(),
                    'paginate' => false,
                ],
            ]);
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'paginate' => true,
            ],
        ]);
    }

    /**
     * Normaliza rangos recibidos como arreglo [from, to] o string "from,to".
     */
    private function parseRange($value): ?array
    {
        if (empty($value)) {
            return null;
        }

        if (is_string($value)) {
            $parts = array_filter(array_map('trim', explode(',', $value)));
        } elseif (is_array($value)) {
            $parts = array_values($value);
        } else {
            return null;
        }

        if (count($parts) !== 2) {
            return null;
        }

        return [
            date('Y-m-d', strtotime($parts[0])),
            date('Y-m-d', strtotime($parts[1])),
        ];
    }

    /**
     * Crea una orden de compra (compatibilidad legacy).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'            => ['required', 'exists:clients,id'],
            'order_consecutive'    => ['required', 'string'],
            'contact'              => ['required', 'string'],
            'phone'                => ['required', 'string'],
            'status'               => ['required', 'string', 'in:pending,processing,completed,cancelled,parcial_status'],
            'trm'                  => ['required'],
            'observations'         => ['nullable', 'string'],
            'tag_email_pedidos'    => ['nullable'],
            'tag_email_despachos'  => ['nullable'],
            'subject_client'       => ['nullable', 'string'],
            'createdOrderDate'     => ['nullable', 'date'],
            'products'             => ['required', 'array'],
            'products.*.product_id'=> ['required', 'integer'],
            'products.*.quantity'  => ['required'],
            'products.*.price'     => ['nullable'],
            'products.*.branch_office_id' => ['required'],
            'attachment'           => ['nullable', 'file', 'mimes:pdf', 'max:4096'],
        ]);

        try {
            \DB::beginTransaction();

            Log::info('PAYLOAD OC STORE', [
                'raw' => $request->all(),
            ]);

            // Mezclar flags new_win / muestra desde el raw si el validador los omitió
            $validatedProducts = $validated['products'];
            $rawProducts = $request->input('products', []);
            foreach ($validatedProducts as $idx => $prod) {
                if (!array_key_exists('new_win', $prod) && isset($rawProducts[$idx]['new_win'])) {
                    $validatedProducts[$idx]['new_win'] = $rawProducts[$idx]['new_win'];
                }
                if (!array_key_exists('muestra', $prod) && isset($rawProducts[$idx]['muestra'])) {
                    $validatedProducts[$idx]['muestra'] = $rawProducts[$idx]['muestra'];
                }
            }

            Log::info('VALIDATED PRODUCTS (after merge)', ['products' => $validatedProducts]);

            $data = [
                'client_id'          => $validated['client_id'],
                'order_consecutive'  => $validated['order_consecutive'],
                'contact'            => $validated['contact'],
                'phone'              => $validated['phone'],
                'status'             => $validated['status'],
                'observations'       => $validated['observations'] ?? null,
                'tag_email_pedidos'  => $validated['tag_email_pedidos'] ?? null,
                'tag_email_despachos'=> $validated['tag_email_despachos'] ?? null,
                'subject_client'     => $validated['subject_client'] ?? null,
                'order_creation_date'=> $validated['createdOrderDate'] ?? now()->format('Y-m-d'),
                'trm'                => $this->normalizeTrm($validated['trm']),
            ];

            // trm_updated_at si cambió frente a trm_initial
            if ($request->get('trm_initial') !== null && $request->get('trm_initial') != $validated['trm']) {
                $data['trm_updated_at'] = $data['order_creation_date'];
            }

            // adjunto
            if ($request->hasFile('attachment')) {
                $data['attachment'] = $request->file('attachment')->store('attachments', 'public');
            }

            $purchaseOrder = PurchaseOrder::create($data);
            // Append id al consecutivo (legacy)
            $purchaseOrder->order_consecutive = $purchaseOrder->id . '-' . $purchaseOrder->order_consecutive;
            if (empty($purchaseOrder->subject_client)) {
                $purchaseOrder->subject_client = 'Orden de Compra - ' . $purchaseOrder->order_consecutive;
            }
            $purchaseOrder->save();

            // Productos
            $this->syncProducts($purchaseOrder, $validatedProducts);

            // Comentario de orden
            if (!empty($validated['observations'])) {
                $purchaseOrder->comments()->create([
                    'user_id' => Auth::id(),
                    'text'    => $validated['observations'],
                    'type'    => 'order_comment',
                ]);
            }

            \DB::commit();

            // Enviar emails después del commit
            try {
                $this->sendPurchaseOrderEmails($purchaseOrder, $validated);
            } catch (\Exception $e) {
                Log::warning('Error enviando emails de orden de compra', [
                    'order_id' => $purchaseOrder->id,
                    'error' => $e->getMessage()
                ]);
                // No fallar la creación de la orden si falla el envío de emails
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden creada correctamente',
                'data'    => $purchaseOrder->fresh(['products', 'client']),
            ], 201);
        } catch (ValidationException $e) {
            \DB::rollBack();
            return response()->json(['errors' => $e->validator->errors()], 422);
        } catch (\Throwable $e) {
            \DB::rollBack();
            Log::error('Error creando orden', [
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la orden: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Actualiza la orden (compatibilidad legacy).
     */
    public function update(Request $request, int $orderId): JsonResponse
    {
        $purchaseOrder = PurchaseOrder::findOrFail($orderId);

        $validated = $request->validate([
            'order_consecutive'    => ['required', 'string', new UniqueOrderConsecutiveForClient($request->client_id, $purchaseOrder->id)],
            'client_id'            => ['required', 'exists:clients,id'],
            'contact'              => ['required', 'string'],
            'phone'                => ['required', 'string'],
            'status'               => ['required', 'string', 'in:pending,processing,completed,cancelled,parcial_status'],
            'trm'                  => ['required'],
            'observations'         => ['nullable', 'string'],
            'tag_email_pedidos'    => ['nullable'],
            'tag_email_despachos'  => ['nullable'],
            'subject_client'       => ['nullable', 'string'],
            'createdOrderDate'     => ['nullable', 'date'],
            'products'             => ['required', 'array'],
            'products.*.id'        => ['nullable', 'integer'], // Allow ID for updating existing products
            'products.*.product_id'=> ['required', 'integer'],
            'products.*.quantity'  => ['required'],
            'products.*.price'     => ['nullable'],
            'products.*.new_win'   => ['nullable'],
            'products.*.muestra'   => ['nullable'],
            'products.*.delivery_date' => ['nullable'],
            'products.*.branch_office_id' => ['required'],
            'attachment'           => ['nullable', 'file', 'mimes:pdf', 'max:4096'],
        ]);

        try {
            \DB::beginTransaction();

            $purchaseOrder->order_consecutive = $validated['order_consecutive'];
            $purchaseOrder->client_id = $validated['client_id'];
            $purchaseOrder->contact = $validated['contact'];
            $purchaseOrder->phone = $validated['phone'];
            $purchaseOrder->status = $validated['status'];
            $purchaseOrder->observations = $validated['observations'] ?? null;
            $purchaseOrder->tag_email_pedidos = $validated['tag_email_pedidos'] ?? null;
            $purchaseOrder->tag_email_despachos = $validated['tag_email_despachos'] ?? null;
            $purchaseOrder->subject_client = $validated['subject_client'] ?? $purchaseOrder->subject_client;
            $purchaseOrder->order_creation_date = $validated['createdOrderDate'] ?? $purchaseOrder->order_creation_date;
            $purchaseOrder->trm = $this->normalizeTrm($validated['trm']);

            if ($request->hasFile('attachment')) {
                if ($purchaseOrder->attachment) {
                    Storage::disk('public')->delete($purchaseOrder->attachment);
                }
                $purchaseOrder->attachment = $request->file('attachment')->store('attachments', 'public');
            }

            $purchaseOrder->save();

            $this->syncProductsForUpdate($purchaseOrder, $validated['products']);

            // Handle observations comments - UPDATE existing or CREATE new (legacy compatibility)
            if (!empty($validated['observations']) && $purchaseOrder->observations != $validated['observations']) {
                $existingComment = $purchaseOrder->comments()
                    ->where('type', 'order_comment')
                    ->where('user_id', Auth::id())
                    ->latest()
                    ->first();

                if ($existingComment) {
                    if ($existingComment->text != $validated['observations']) {
                        $existingComment->update(['text' => $validated['observations']]);
                    }
                } else {
                    $purchaseOrder->comments()->create([
                        'user_id' => Auth::id(),
                        'text'    => $validated['observations'],
                        'type'    => 'order_comment',
                    ]);
                }
            }

            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Orden actualizada correctamente',
                'data'    => $purchaseOrder->fresh(['products', 'client']),
            ]);
        } catch (ValidationException $e) {
            \DB::rollBack();
            return response()->json(['errors' => $e->validator->errors()], 422);
        } catch (\Throwable $e) {
            \DB::rollBack();
            Log::error('Error actualizando orden', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la orden: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Elimina adjunto de la orden.
     */
    public function deleteAttachment(int $orderId): JsonResponse
    {
        $purchaseOrder = PurchaseOrder::findOrFail($orderId);

        if ($purchaseOrder->attachment) {
            Storage::disk('public')->delete($purchaseOrder->attachment);
            $purchaseOrder->attachment = null;
            $purchaseOrder->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Muestra el PDF de la orden de compra.
     */
    public function downloadPdf(int $id): \Illuminate\Http\Response
    {
        $order = PurchaseOrder::with(['client', 'products', 'branchOffice'])->findOrFail($id);
        $pdfData = $this->generatePdfOrder($order);

        return response($pdfData)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="order-' . $order->order_consecutive . '.pdf"');
    }

    /**
     * Genera y descarga la proforma de la orden de compra.
     */
    public function downloadProforma(int $id, Request $request): \Illuminate\Http\Response
    {
        $purchase = PurchaseOrder::with(['client', 'products'])->findOrFail($id);
        
        $params = [
            'country' => $request->query('country', 'CO'),
            'date' => $request->query('date', now()->format('Y-m-d')),
            'flete' => (float) $request->query('flete', 0),
            'trm' => (float) $request->query('trm', $purchase->trm),
        ];

        $pdfContent = $this->generateProformaPdfContent($purchase, $params);

        // Marcar proforma como generada
        $purchase->proforma_generada = true;
        $purchase->save();

        $filename = ($params['country'] === 'CO' ? 'proforma-nacional-' : 'proforma-export-') . $purchase->order_consecutive . '.pdf';

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Reenvía los correos originales de la orden de compra (pedido y despacho).
     */
    public function resendOrder(int $id): JsonResponse
    {
        try {
            $purchaseOrder = PurchaseOrder::findOrFail($id);
            
            $this->sendPurchaseOrderEmails($purchaseOrder, [
                'tag_email_pedidos' => $purchaseOrder->tag_email_pedidos,
                'tag_email_despachos' => $purchaseOrder->tag_email_despachos,
                'attachment' => $purchaseOrder->attachment,
            ]);

            return response()->json(['success' => true, 'message' => 'Correos de la orden reenviados exitosamente.']);
        } catch (\Exception $e) {
            \Log::error('Error al reenviar orden: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al reenviar los correos de la orden.'], 500);
        }
    }

    /**
     * Reenvía la proforma al cliente.
     */
    public function resendProforma(int $id, Request $request): JsonResponse
    {
        try {
            $purchase = PurchaseOrder::with(['client', 'products'])->findOrFail($id);
            
            $params = [
                'country' => $request->input('country', 'CO'),
                'date' => $request->input('date', now()->format('Y-m-d')),
                'flete' => (float) $request->input('flete', 0),
                'trm' => (float) $request->input('trm', $purchase->trm),
            ];

            $pdfContent = $this->generateProformaPdfContent($purchase, $params);

            $client = $purchase->client;
            $toEmail = $client->email;
            
            if (empty($toEmail)) {
                return response()->json(['success' => false, 'message' => 'El cliente no tiene un correo configurado.'], 422);
            }

            $ccEmails = [];
            if ($client->executive_email) {
                $ccEmails[] = $client->executive_email;
            }

            // CC desde procesos (orden_de_compra / pedido)
            $processEmails = \App\Models\Process::whereIn('process_type', ['orden_de_compra', 'pedido'])
                ->pluck('email')
                ->toArray();
            
            foreach ($processEmails as $email) {
                $ccEmails = array_merge($ccEmails, array_map('trim', explode(',', $email)));
            }
            $ccEmails = array_filter(array_unique($ccEmails));

            \Mail::to($toEmail)
                ->cc($ccEmails)
                ->send(new \App\Mail\ProformaMail($purchase, $pdfContent));

            return response()->json(['success' => true, 'message' => 'Proforma reenviada exitosamente.']);
        } catch (\Exception $e) {
            \Log::error('Error al reenviar proforma: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al reenviar proforma.'], 500);
        }
    }

    /**
     * Genera el contenido binario del PDF de la proforma.
     */
    protected function generateProformaPdfContent(PurchaseOrder $purchase, array $params): string
    {
        $client = $purchase->client;
        $country = $params['country'];
        $date = $params['date'];
        $fleteValue = $params['flete'];
        $trm = $params['trm'];
        
        $isNational = ($country === 'CO');

        // Calcular ítems
        $items = [];
        $subtotal = 0;
        $idx = 1;

        foreach ($purchase->products as $product) {
            $quantity = $product->pivot->quantity;
            // Usar precio efectivo: si pivot->price > 0, usar ese, sino usar product->price
            $unitPriceUSD = ($product->pivot->price > 0) ? $product->pivot->price : $product->price;

            if ($isNational) {
                $unitPrice = round($unitPriceUSD * $trm, 2);
            } else {
                $unitPrice = $unitPriceUSD;
            }

            $total = round($quantity * $unitPrice, 2);
            $subtotal += $total;

            $cleanCode = $product->code;
            if ($client->nit) {
                $cleanCode = preg_replace('/^' . preg_quote($client->nit, '/') . '[-_\s]*/', '', $cleanCode);
            }

            $items[] = [
                'item' => $idx++,
                'code' => $cleanCode,
                'description' => $product->product_name,
                'unit' => 'KG',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total
            ];
        }

        $subtotal += $fleteValue;

        // Impuestos
        $totals = [
            'subtotal' => $subtotal,
            'flete' => $fleteValue,
            'iva' => 0,
            'rete_ica' => 0,
            'rete_iva' => 0,
            'rete_fte' => 0,
            'total' => $subtotal
        ];

        if ($isNational) {
            $totals['iva'] = $client->iva ? ($subtotal * ($client->iva / 100)) : 0;
            $totals['rete_ica'] = $client->ica ? ($subtotal * ($client->ica / 100)) : 0;
            $totals['rete_iva'] = $client->reteiva ? ($subtotal * ($client->reteiva / 100)) : 0;
            $totals['rete_fte'] = $client->retefuente ? ($subtotal * ($client->retefuente / 100)) : 0;
            $totals['total'] = $subtotal + $totals['iva'] - $totals['rete_ica'] - $totals['rete_iva'] - $totals['rete_fte'];
        }

        $carbonDate = \Carbon\Carbon::parse($date)->locale('es');
        $fechaTrmText = $carbonDate->isoFormat('dddd DD [de] MMMM [del] YYYY');

        $data = [
            'logoUrl' => $this->getLogoBase64(),
            'orden_compra' => $purchase->order_consecutive,
            'fecha_trm' => $fechaTrmText,
            'trm' => $isNational ? $trm : null,
            'currency' => $isNational ? 'COP' : 'USD',
            'company' => [
                'name' => 'FINEAROM SAS',
                'nit' => '900.220.672-8',
                'address' => 'CRA 50 134 D 31',
                'city' => 'BOGOTÁ - COLOMBIA',
                'phone' => '57 1 6150035',
                'email' => 'servicio.cliente@finearom.com'
            ],
            'client' => [
                'name' => $client->client_name,
                'nit' => $client->nit,
                'address' => $client->address,
                'city' => $client->city,
                'phone' => $client->phone,
                'email' => $client->email,
            ],
            'invoice' => [
                'number' => date('Ymd') . $purchase->id,
            ],
            'items' => $items,
            'client_rates' => [
                'iva' => $client->iva,
                'ica' => $client->ica,
                'reteiva' => $client->reteiva,
                'retefuente' => $client->retefuente,
            ],
            'totals' => $totals
        ];

        $pdf = \PDF::loadView('pdf.proforma', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'defaultFont' => 'Arial'
        ]);

        return $pdf->output();
    }

    /**
     * Actualiza estado de la orden, guarda datos de parcial y adjunto (compatibilidad legacy).
     */
    public function updateStatus(Request $request, int $orderId): JsonResponse
    {
        // Validaciones personalizadas con mensajes amigables
        $validator = \Validator::make($request->all(), [
            'status'          => ['required', 'string', 'in:pending,processing,completed,parcial_status,cancelled'],
            'invoiceNumber'   => ['nullable', 'string', 'max:255'],
            'dispatchDate'    => ['nullable', 'string'],
            'trackingNumber'  => ['nullable', 'string', 'max:255'],
            'trm'             => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'transporter'     => ['nullable', 'string', 'max:255'],
            'emails'          => ['nullable', 'string'],
            'observations'    => ['nullable', 'string'],
            'parcials'        => ['nullable', 'string'],
            'invoice_pdf'     => ['nullable', 'file', 'mimes:pdf', 'max:4096'],
        ], [
            'status.required' => 'El estado es obligatorio',
            'status.in' => 'El estado seleccionado no es válido',
            'invoiceNumber.max' => 'El número de factura no puede exceder 255 caracteres',
            'trackingNumber.max' => 'El número de guía no puede exceder 255 caracteres',
            'trm.numeric' => 'El TRM debe ser un número válido',
            'trm.min' => 'El TRM debe ser mayor o igual a 0',
            'trm.max' => 'El TRM no puede ser mayor a 99999. Verifica que el formato sea correcto (ejemplo: 3817.93)',
            'transporter.max' => 'El nombre de la transportadora no puede exceder 255 caracteres',
            'invoice_pdf.file' => 'El archivo PDF no es válido',
            'invoice_pdf.mimes' => 'El archivo debe ser un PDF',
            'invoice_pdf.max' => 'El archivo PDF no puede superar 4MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            \DB::beginTransaction();

            $order = PurchaseOrder::with(['client', 'products', 'partials', 'comments'])->findOrFail($orderId);

            // Update order fields
            $order->status = $validated['status'];

            if (!empty($validated['invoiceNumber'])) {
                $order->invoice_number = $validated['invoiceNumber'];
            }

            // Mejorar el manejo de fecha de despacho
            if (isset($validated['dispatchDate']) && !empty($validated['dispatchDate']) && $validated['dispatchDate'] !== 'null') {
                try {
                    // Clean date format (remove timezone info if present)
                    $cleanDate = preg_replace('/\s*\([^)]*\)/', '', $validated['dispatchDate']);
                    $parsedDate = \Carbon\Carbon::parse($cleanDate);
                    $order->dispatch_date = $parsedDate->format('Y-m-d');
                } catch (\Exception $e) {
                    throw new \Exception('La fecha de despacho no tiene un formato válido. Por favor selecciona una fecha válida.');
                }
            }

            if (!empty($validated['trackingNumber'])) {
                $order->tracking_number = $validated['trackingNumber'];
            }

            if (!empty($validated['trm'])) {
                $order->trm = $this->normalizeTrm($validated['trm']);
            }

            // Handle file upload
            $invoicePdfPath = null;
            if ($request->hasFile('invoice_pdf')) {
                $invoicePdfPath = $request->file('invoice_pdf')->store('invoice_pdf', 'public');
                $order->invoice_pdf = $invoicePdfPath;
            }

            // Save observations to extra field (legacy compatibility)
            if (!empty($validated['observations'])) {
                $timestamp = now()->format('Y-m-d H:i:s');
                $observationEntry = $timestamp . ' ' . $validated['observations'];
                $currentObservations = $order->observations_extra ?? '';
                $order->observations_extra = $observationEntry . $currentObservations;
                $order->complete_observation = $validated['observations'];
            }

            $order->save();

            // Process partials: delete existing "real" type and create new ones
            $order->partials()->where('type', 'real')->delete();

            if (!empty($validated['parcials'])) {
                try {
                    $parcials = json_decode($validated['parcials'], true);

                    if (!is_array($parcials)) {
                        throw new \Exception('Los datos de parciales no tienen un formato válido');
                    }

                    foreach ($parcials as $productData) {
                        $productId = $productData['pivot']['product_id'] ?? null;
                        $productOrderId = $productData['pivot']['id'] ?? null;
                        $realPartials = $productData['realPartials'] ?? [];

                        if (!$productId) {
                            throw new \Exception('Falta el ID del producto en los parciales');
                        }

                        foreach ($realPartials as $partialEntry) {
                            $quantity = $partialEntry['quantity'] ?? 0;
                            if ($quantity <= 0) {
                                continue;
                            }

                            // Validar fecha del parcial
                            $partialDate = $partialEntry['date'] ?? null;
                            if ($partialDate) {
                                try {
                                    $partialDate = \Carbon\Carbon::parse($partialDate)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    throw new \Exception('La fecha del parcial no tiene un formato válido: ' . ($partialEntry['date'] ?? 'sin fecha'));
                                }
                            }

                            Partial::create([
                                'order_id'         => $order->id,
                                'product_id'       => $productId,
                                'quantity'         => $quantity,
                                'type'             => 'real',
                                'dispatch_date'    => $partialDate,
                                'trm'              => $partialEntry['trm'] ?? $validated['trm'] ?? null,
                                'invoice_number'   => $validated['invoiceNumber'] ?? null,
                                'tracking_number'  => $partialEntry['tracking_number'] ?? $validated['trackingNumber'] ?? null,
                                'transporter'      => $partialEntry['transporter'] ?? $validated['transporter'] ?? null,
                                'product_order_id' => $productOrderId,
                            ]);
                        }
                    }
                } catch (\JsonException $e) {
                    throw new \Exception('Error al procesar los parciales: formato JSON inválido');
                }
            }

            // Save comment if observations present
            if (!empty($validated['observations'])) {
                $order->comments()->create([
                    'user_id' => auth()->id(),
                    'text'    => $validated['observations'],
                    'type'    => 'new_status_comment',
                ]);
            }

            \DB::commit();

            // Invalidar caché de análisis ya que se actualizaron parciales o estado
            $this->clearAnalyzeCache();

            // Send email if there are observations
            $cleanObservations = trim(strip_tags($validated['observations'] ?? ''));
            if (!empty($cleanObservations)) {
                $this->sendStatusUpdateEmail(
                    $order,
                    $validated['emails'] ?? null,
                    $invoicePdfPath,
                    $validated['observations'] ?? null
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden actualizada exitosamente',
                'data'    => $order->fresh(['client', 'products', 'partials', 'comments']),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \DB::rollBack();
            \Log::error('Purchase order not found: ' . $orderId);

            return response()->json([
                'success' => false,
                'message' => 'No se encontró la orden de compra con ID: ' . $orderId,
            ], 404);

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Error updating purchase order status: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'trace'    => $e->getTraceAsString(),
            ]);

            // Mensajes de error más amigables
            $userMessage = $e->getMessage();

            // Si el mensaje de error es muy técnico, proporcionar uno más amigable
            if (str_contains($userMessage, 'Failed to parse time string') ||
                str_contains($userMessage, 'timezone could not be found')) {
                $userMessage = 'La fecha proporcionada no es válida. Por favor selecciona una fecha correcta.';
            } elseif (str_contains($userMessage, 'Integrity constraint violation')) {
                $userMessage = 'Error de integridad en la base de datos. Verifica que todos los datos sean correctos.';
            } elseif (str_contains($userMessage, 'SQLSTATE')) {
                $userMessage = 'Error en la base de datos. Por favor contacta al administrador.';
            }

            return response()->json([
                'success' => false,
                'message' => $userMessage,
                'error_details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send status update email with proper recipient handling.
     */
    private function sendStatusUpdateEmail(PurchaseOrder $order, ?string $emailsJson, ?string $invoicePdfPath, ?string $statusCommentHtml = null): void
    {
        try {
            // Parse emails from request
            $requestEmails = !empty($emailsJson) ? json_decode($emailsJson, true) : [];
            $requestEmails = is_array($requestEmails) ? $requestEmails : [];

            // If no emails in request, fallback to Process table (legacy compatibility)
            if (empty($requestEmails)) {
                $requestEmails = \App\Models\Process::where('process_type', 'pedido')
                    ->pluck('email')
                    ->toArray();
            }

            // Expand comma-separated emails
            $expandedEmails = [];
            foreach ($requestEmails as $email) {
                if (strpos($email, ',') !== false) {
                    $splitEmails = array_map('trim', explode(',', $email));
                    $expandedEmails = array_merge($expandedEmails, $splitEmails);
                } else {
                    $expandedEmails[] = trim($email);
                }
            }

            // Get client email(s)
            $clientEmail = $order->client->email ?? '';
            $expandedClientEmails = [];
            
            if (!empty($clientEmail)) {
                if (strpos($clientEmail, ',') !== false) {
                    $expandedClientEmails = array_map('trim', explode(',', $clientEmail));
                } else {
                    $expandedClientEmails = [trim($clientEmail)];
                }
            }

            // Determine TO and CC recipients
            $toEmail = null;
            $ccEmails = [];

            if (!empty($expandedClientEmails)) {
                // First client email as TO
                $toEmail = array_shift($expandedClientEmails);
                // Rest of client emails go to CC
                $ccEmails = array_merge($expandedClientEmails, $expandedEmails);
            } else {
                // No client email, use first from request as TO
                $toEmail = !empty($expandedEmails) ? array_shift($expandedEmails) : auth()->user()->email;
                $ccEmails = $expandedEmails;
            }

            // Add executive email to CC if exists and not duplicate
            $executiveEmail = $order->client->executive_email ?? null;
            if (!empty($executiveEmail) && !in_array($executiveEmail, $ccEmails) && $executiveEmail !== $toEmail) {
                $ccEmails[] = $executiveEmail;
            }

            // Remove duplicates and filter
            $ccEmails = array_values(array_unique(array_filter($ccEmails)));

            // Send email
            $mailable = new \App\Mail\PurchaseOrderStatusMail($order, $invoicePdfPath, $statusCommentHtml);
            
            $mail = \Mail::to($toEmail);
            
            if (!empty($ccEmails)) {
                $mail->cc($ccEmails);
            }
            
            $mail->send($mailable);

            \Log::info('Status update email sent successfully', [
                'order_id' => $order->id,
                'to'       => $toEmail,
                'cc'       => $ccEmails,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error sending status update email: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'trace'    => $e->getTraceAsString(),
            ]);
            // Don't throw - email failure shouldn't break the update
        }
    }

    /**
     * Guarda observaciones parciales (compatibilidad legacy) y envía correos.
     */
    public function updateObservations(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'new_observation'      => ['nullable', 'string'],
            'internal_observation' => ['nullable', 'string'],
            'partials'             => ['nullable'], // puede venir como JSON string o arreglo
            'emails-tags'          => ['nullable'],
        ]);

        $order = PurchaseOrder::with(['client', 'partials'])->findOrFail($orderId);

        try {
            \DB::beginTransaction();

            // Parciales temporales
            $order->partials()->where('type', 'temporal')->delete();

            $partialsPayload = $request->input('partials', []);
            if (is_string($partialsPayload)) {
                $partialsPayload = json_decode($partialsPayload, true) ?: [];
            }

            if (is_array($partialsPayload)) {
                foreach ($partialsPayload as $partialGroup) {
                    $pivot = $partialGroup['pivot'] ?? [];
                    $productId = $pivot['product_id'] ?? null;
                    $productOrderId = $pivot['id'] ?? null;
                    $cierreCartera = $pivot['cierre_cartera'] ?? null;
                    $partials = $partialGroup['partials'] ?? [];

                    if (! $productId || ! $productOrderId) {
                        continue;
                    }

                    // Actualizar cierre_cartera en la tabla pivote
                    $cierreCarteraValue = null;
                    if (! empty($cierreCartera)) {
                        try {
                            // Normaliza a datetime; si solo viene la fecha, se convierte con hora 00:00:00
                            $cierreCarteraValue = Carbon::parse($cierreCartera)->toDateTimeString();
                        } catch (\Throwable $parseError) {
                            \Log::warning('cierre_cartera inválido, se almacena null', [
                                'order_id' => $orderId,
                                'product_order_id' => $productOrderId,
                                'raw' => $cierreCartera,
                                'error' => $parseError->getMessage(),
                            ]);
                            $cierreCarteraValue = null;
                        }
                    }

                    \DB::table('purchase_order_product')
                        ->where('id', $productOrderId)
                        ->update(['cierre_cartera' => $cierreCarteraValue]);

                    if (is_array($partials)) {
                        foreach ($partials as $partialEntry) {
                            $quantity = $partialEntry['quantity'] ?? 0;
                            $dispatchDate = $partialEntry['date'] ?? null;

                            if ($quantity <= 0 || empty($dispatchDate)) {
                                continue;
                            }

                            $order->partials()->create([
                                'product_id'        => $productId,
                                'product_order_id'  => $productOrderId,
                                'quantity'          => $quantity,
                                'dispatch_date'     => $dispatchDate,
                                'type'              => 'temporal',
                            ]);
                        }
                    }
                }
            }

            // Observaciones visibles para cliente
            if (! empty($validated['new_observation'])) {
                $currentObservations = $order->observations_extra ?? '';
                $order->observations_extra = $validated['new_observation'] . $currentObservations;
            }

            $cleanInternal = trim(strip_tags($validated['internal_observation'] ?? ''));
            // Ignore placeholder "internal" or empty content
            $shouldStoreInternal = $cleanInternal !== '' && strtolower($cleanInternal) !== 'internal';

            // Observaciones internas (solo planta)
            if ($shouldStoreInternal) {
                $currentInternal = $order->internal_observations ?? '';
                $order->internal_observations = $validated['internal_observation'] . $currentInternal;
            }

            $order->save();

            // Comentario de trazabilidad
            if (! empty($validated['new_observation'])) {
                $order->comments()->create([
                    'user_id' => auth()->id(),
                    'text'    => $validated['new_observation'],
                    'type'    => 'new_comment',
                ]);
            }

            \DB::commit();

            // Invalidar caché de análisis ya que se actualizaron parciales temporales
            $this->clearAnalyzeCache();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::error('Error al guardar observaciones', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudieron guardar las observaciones',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }

        // Enviar correos solo si hay observación visible
        $cleanObservation = trim(strip_tags($validated['new_observation'] ?? ''));
            if (! empty($cleanObservation)) {
                $order->loadMissing('client');
                $this->sendObservationEmails(
                    $order,
                    $validated['new_observation'],
                    $shouldStoreInternal ? $validated['internal_observation'] : null,
                    $request
                );
            }

        return response()->json([
            'success' => true,
            'message' => 'Observaciones guardadas correctamente',
        ]);
    }

    /**
     * Envía correos de observaciones replicando la lógica legacy.
     */
    private function sendObservationEmails(PurchaseOrder $order, string $observationHtml, ?string $internalObservation, Request $request): void
    {
        try {
            $tablesOnly = $this->extractHtmlTables($observationHtml);
            $userEmail = auth()->user()?->email ?? config('mail.from.address');
            $internalSection = '';
            if (! empty($internalObservation)) {
                $internalSection = '<br><br><strong>Observaciones internas (solo planta)</strong><br>' . $internalObservation;
            }

            $dsn = $this->resolveMailerDsn($userEmail);
            if (empty($dsn)) {
                \Log::warning('No se pudo enviar correo de observaciones: DSN no configurado');
                return;
            }
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            // Correos destino (tags o procesos)
            $emailsRaw = $request->input('emails-tags', []);
            $tagEmails = $this->normalizeEmails($emailsRaw);
            $processEmails = $this->normalizeEmails(
                Process::where('process_type', 'orden_de_compra')->pluck('email')->toArray()
            );

            // Correos que se consideran del cliente (no deben recibir internos)
            $clientEmails = $this->normalizeEmails([
                $order->client->email,
                $order->client->dispatch_confirmation_email,
                $order->tag_email_despachos,
                $order->tag_email_pedidos,
            ]);

            // Solo internos: despachos/planta (procesos) + tags, excluyendo correos cliente
            $internalPool = array_merge($processEmails, $tagEmails);
            $ccEmails = array_values(array_unique(array_diff($internalPool, $clientEmails)));

            $primaryTo = array_shift($ccEmails) ?: null;

            // Usar el subject_client que se guardó al enviar el correo de despacho
            // Si por alguna razón está vacío, generar el formato estándar
            if (empty($order->subject_client)) {
                $subject = 'Re: ' .
                    ($order->is_new_win == 1 ? 'NEW WIN - ' : '') .
                    'Pedido - ' .
                    $order->client->client_name . ' - ' .
                    $order->client->nit . ' - ' .
                    $order->order_consecutive;
            } else {
                $subject = 'Re: ' . $order->subject_client;
            }

            $threadId = $order->message_despacho_id ?: $order->message_id;

            // --- PRIVACY ENHANCEMENT ---
            // Identify client emails to exclude them from internal notification
            $internalCcEmails = array_values(array_diff($ccEmails, $clientEmails));
            
            // Re-evaluate primaryTo if it was the client
            $internalTo = $primaryTo;
            if (in_array($internalTo, $clientEmails)) {
                $internalTo = array_shift($internalCcEmails);
            }

            $internalBody = view('emails.purchase_order_observation', [
                'order'           => $order,
                'observationHtml' => $observationHtml,
                'internalHtml'    => $internalObservation,
                'forClient'       => false,
            ])->render();

            if ($internalTo) {
                $internalCcAddresses = array_map(fn ($email) => new Address($email), $internalCcEmails);

                $internalEmail = (new Email())
                    ->from($userEmail)
                    ->to($internalTo)
                    ->cc(...$internalCcAddresses)
                    ->subject($subject)
                    ->html($internalBody);

                if ($threadId) {
                    $internalEmail->getHeaders()->addTextHeader('In-Reply-To', '<' . $threadId . '>');
                    $internalEmail->getHeaders()->addTextHeader('References', '<' . $threadId . '>');
                }

                $mailer->send($internalEmail);
            }

            // Always send tables-only to client if it's not empty
            if (! empty($tablesOnly)) {
                foreach ($clientEmails as $cEmail) {
                    if (!$this->isValidEmail($cEmail)) continue;

                    $clientBody = view('emails.purchase_order_observation', [
                        'order'           => $order,
                        'observationHtml' => $tablesOnly,
                        'forClient'       => true,
                    ])->render();

                    $clientMail = (new Email())
                        ->from($userEmail)
                        ->to($cEmail)
                        ->subject($subject)
                        ->html($clientBody);

                        //cambio

                    if ($threadId) {
                        $clientMail->getHeaders()->addTextHeader('In-Reply-To', '<' . $threadId . '>');
                        $clientMail->getHeaders()->addTextHeader('References', '<' . $threadId . '>');
                    }

                    $mailer->send($clientMail);
                }
            }
        } catch (\Throwable $e) {
            \Log::error('Error enviando correo de observaciones', [
                'order_id' => $order->id,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrae solo tablas HTML del cuerpo (para correo al cliente).
     */
    private function extractHtmlTables(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0) {
            return '';
        }

        $newDom = new \DOMDocument();
        $newDom->encoding = 'UTF-8';

        foreach ($tables as $table) {
            $imported = $newDom->importNode($table, true);
            $newDom->appendChild($imported);
        }

        return $newDom->saveHTML() ?: '';
    }

    /**
     * Valida formato de email.
     */
    private function isValidEmail(?string $email): bool
    {
        if (empty($email)) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Normaliza un set de correos: soporta string, json string o array, divide por comas y limpia espacios.
     */
    private function normalizeEmails($emails): array
    {
        if (is_string($emails)) {
            $decoded = json_decode($emails, true);
            $emails = is_array($decoded) ? $decoded : explode(',', $emails);
        }

        if (! is_array($emails)) {
            return [];
        }

        $normalized = [];
        foreach ($emails as $email) {
            $parts = strpos((string) $email, ',') !== false ? explode(',', (string) $email) : [$email];
            foreach ($parts as $part) {
                $clean = trim((string) $part);
                if ($clean !== '') {
                    $normalized[] = $clean;
                }
            }
        }

        return $normalized;
    }

    /**
     * Resuelve DSN según usuario (compatibilidad analista.operaciones).
     */
    private function resolveMailerDsn(string $userEmail): ?string
    {
        $dsn = env('MAILER_DSN') ?: config('custom.dsn') ?: config('mail.mailers.smtp.url');

        if ($userEmail === 'analista.operaciones@finearom.com' && env('MAILER_DSN_MARLON')) {
            $dsn = env('MAILER_DSN_MARLON');
        }

        return $dsn ?: null;
    }

    /**
     * Sincroniza productos usando attach (legacy compatibility - para store)
     */
    private function syncProducts(PurchaseOrder $purchaseOrder, array $products): void
    {
        $isNewWin = 0;
        $isMuestra = 0;

        foreach ($products as $product) {
            // Normalizar flags new_win y muestra de forma robusta (acepta "true","1","on","yes")
            $newWinRaw = $product['new_win'] ?? null;
            $muestraRaw = $product['muestra'] ?? null;

            $newWinFlag = !in_array(strtolower((string) $newWinRaw), ['0', 'false', 'off', 'no', '', 'undefined'], true);
            $muestraFlag = !in_array(strtolower((string) $muestraRaw), ['0', 'false', 'off', 'no', '', 'undefined'], true);

            Log::info('SYNC PRODUCTS (create) flags', [
                'product_id' => $product['product_id'] ?? null,
                'raw_new_win' => $newWinRaw,
                'raw_muestra' => $muestraRaw,
                'newWinFlag' => $newWinFlag,
                'muestraFlag' => $muestraFlag,
            ]);

            if ($newWinFlag && !$isNewWin) {
                $isNewWin = 1;
            }

            if ($muestraFlag && !$isMuestra) {
                $isMuestra = 1;
            }

            // Solo guardar precio si fue editado manualmente
            $priceToSave = 0;
            if (!empty($product['price_manually_changed']) && $product['price_manually_changed']) {
                $priceToSave = $product['price'] ?? 0;
            }

            $purchaseOrder->products()->attach($product['product_id'], [
                'quantity' => $product['quantity'],
                'price' => $priceToSave,
                'new_win' => $newWinFlag ? 1 : 0,
                'muestra' => $muestraFlag ? 1 : 0,
                'branch_office_id' => $product['branch_office_id'],
                'delivery_date' => $product['delivery_date'] ?? now()->format('Y-m-d'),
            ]);
        }

        $purchaseOrder->is_new_win = $isNewWin;
        $purchaseOrder->is_muestra = $isMuestra;

        $purchaseOrder->save();
    }

    /**
     * Sincroniza productos para update (legacy compatibility - crea/actualiza/elimina)
     */
    private function syncProductsForUpdate(PurchaseOrder $purchaseOrder, array $products): void
    {
        try {
            $purchaseOrderId = $purchaseOrder->id;
            $isNewWin = 0;
            $isMuestra = 0;
            $keptIds = [];

            // Obtener IDs actuales antes de procesar
            $currentIds = \DB::table('purchase_order_product')
                ->where('purchase_order_id', $purchaseOrderId)
                ->pluck('id')
                ->toArray();

            foreach ($products as $productData) {
                // Procesar flags
                $newWinFlag = !in_array(strtolower((string) ($productData['new_win'] ?? '')), ['0', 'false', 'off', 'no', '', 'undefined'], true);
                $muestraFlag = !in_array(strtolower((string) ($productData['muestra'] ?? '')), ['0', 'false', 'off', 'no', '', 'undefined'], true);

                if ($newWinFlag) $isNewWin = 1;
                if ($muestraFlag) $isMuestra = 1;

                // Solo guardar precio si fue editado manualmente
                $priceToSave = 0;
                if (!empty($productData['price_manually_changed']) && $productData['price_manually_changed']) {
                    $priceToSave = (float) ($productData['price'] ?? 0);
                }

                // Preparar datos
                $updateData = [
                    'product_id' => (int) ($productData['product_id'] ?? 0),
                    'quantity' => (float) ($productData['quantity'] ?? 0),
                    'price' => $priceToSave,
                    'branch_office_id' => (int) ($productData['branch_office_id'] ?? 0),
                    'new_win' => $newWinFlag ? 1 : 0,
                    'muestra' => $muestraFlag ? 1 : 0,
                ];

                // Agregar delivery_date si existe
                if (!empty($productData['delivery_date'])) {
                    $updateData['delivery_date'] = $productData['delivery_date'];
                }

                $processedRecord = null;

                // ¿Tiene ID válido?
                if (isset($productData['id']) && is_numeric($productData['id'])) {
                    $id = (int) $productData['id'];

                    // Buscar por ID en la tabla pivot
                    $existing = \DB::table('purchase_order_product')
                        ->where('id', $id)
                        ->where('purchase_order_id', $purchaseOrderId)
                        ->first();

                    if ($existing) {
                        // Actualizar registro existente
                        \DB::table('purchase_order_product')
                            ->where('id', $id)
                            ->update($updateData);
                        $processedRecord = (object)['id' => $id];
                    } else {
                        // El ID no existe, crear nuevo
                        $newId = \DB::table('purchase_order_product')->insertGetId([
                            'purchase_order_id' => $purchaseOrderId,
                            ...$updateData
                        ]);
                        $processedRecord = (object)['id' => $newId];
                    }
                } else {
                    // Sin ID válido, crear nuevo
                    $newId = \DB::table('purchase_order_product')->insertGetId([
                        'purchase_order_id' => $purchaseOrderId,
                        ...$updateData
                    ]);
                    $processedRecord = (object)['id' => $newId];
                }

                if ($processedRecord) {
                    $keptIds[] = $processedRecord->id;
                }
            }

            // Eliminar productos que ya no están en la lista
            $idsToDelete = array_diff($currentIds, $keptIds);
            if (!empty($idsToDelete)) {
                \DB::table('purchase_order_product')
                    ->whereIn('id', $idsToDelete)
                    ->delete();
            }

            // Actualizar flags en la orden
            $purchaseOrder->is_new_win = $isNewWin;
            $purchaseOrder->is_muestra = $isMuestra;
            $purchaseOrder->save();

        } catch (\Exception $e) {
            Log::error('Error en syncProductsForUpdate', [
                'mensaje' => $e->getMessage(),
                'linea' => $e->getLine(),
                'archivo' => $e->getFile(),
                'purchase_order_id' => $purchaseOrder->id ?? null
            ]);

            throw $e;
        }
    }

    /**
     * Normaliza TRM siguiendo reglas legacy.
     */
    private function normalizeTrm($value): float
    {
        $value = trim((string) $value);
        if ($value === '' || in_array(strtoupper($value), ['N/A', 'NA', 'NULL'])) {
            return 0.0;
        }

        $value = str_ireplace(['$', 'ƒª', 'USD', 'COP', ' '], '', $value);
        $value = preg_replace('/^[^\d\-]*([\d\.,\/]+)/', '$1', $value);

        if (preg_match('/^[\d,\.\/]+$/', $value) && strpos($value, '/') !== false) {
            $expr = str_replace(',', '', $value);
            $expr = preg_replace('/[^0-9.\/]/', '', $expr);
            if (!preg_match('/^[\d\.]+\/[\d\.]+$/', $expr)) {
                return 0.0;
            }
            [$numerador, $denominador] = explode('/', $expr);
            $denominador = (float) $denominador ?: 1;
            return (float) $numerador / $denominador;
        }

        $value = str_replace(['.', ','], ['', '.'], $value);
        if (is_numeric($value)) {
            return (float) $value;
        }

        $digits = preg_replace('/[^\d]/', '', $value);
        if (strlen($digits) <= 4) {
            return (float) $digits;
        }

        $entero = substr($digits, 0, 4);
        $decimal = substr($digits, 4);
        return (float) ($entero . '.' . $decimal);
    }

    /**
     * API: devuelve la orden con relaciones completas para el frontend (modal parciales).
     */
    public function show(int $id): JsonResponse
    {
        $purchaseOrder = PurchaseOrder::with([
            'client',
            'products', // Eager load productos via belongsToMany
            'partials'
        ])->findOrFail($id);

        // Transformar la estructura para que sea compatible con el frontend
        // El frontend espera products con pivot
        $purchaseOrder->products->transform(function ($product) use ($purchaseOrder) {
            // Convertir el producto a array
            $productData = $product->toArray();

            // Los datos del pivot ya vienen en $product->pivot desde belongsToMany
            // Solo necesitamos asegurar que todos los campos estén presentes
            $productData['pivot'] = [
                'id' => $product->pivot->id,
                'purchase_order_id' => $product->pivot->purchase_order_id,
                'product_id' => $product->pivot->product_id,
                'quantity' => $product->pivot->quantity,
                'price' => $product->pivot->price,
                'branch_office_id' => $product->pivot->branch_office_id,
                'new_win' => $product->pivot->new_win,
                'muestra' => $product->pivot->muestra,
                'delivery_date' => $product->pivot->delivery_date,
                'parcial' => $product->pivot->parcial,
            ];

            // Inicializar arrays de parciales
            $productData['partials'] = [];
            $productData['realPartials'] = [];

            // Filtrar parciales que pertenecen a este producto
            foreach ($purchaseOrder->partials as $partial) {
                if ($partial->product_order_id === $product->pivot->id) {
                    $partialData = [
                        'quantity' => $partial->quantity,
                        'date' => $partial->dispatch_date ? (is_string($partial->dispatch_date) ? explode('T', $partial->dispatch_date)[0] : $partial->dispatch_date->format('Y-m-d')) : null,
                        'product_id' => $partial->product_id,
                        'order_id' => $partial->order_id,
                        'tracking_number' => $partial->tracking_number,
                        'transporter' => $partial->transporter,
                        'trm' => $partial->trm,
                        'invoice_number' => $partial->invoice_number,
                        'product_order_id' => $partial->product_order_id
                    ];

                    if ($partial->type === 'temporal') {
                        $productData['partials'][] = $partialData;
                    } else {
                        $productData['realPartials'][] = $partialData;
                    }
                }
            }

            return $productData;
        });

        return response()->json($purchaseOrder);
    }

    /**
     * Get TRM (Tasa Representativa del Mercado) for a specific date
     * Usa el TrmService centralizado con sistema de fallback completo
     */
    public function getTrm(Request $request): JsonResponse
    {
        $customDate = $request->query('custom_date');

        try {
            $trmValue = $this->trmService->getTrm($customDate);

            return response()->json([
                'success' => true,
                'trm' => $trmValue,
                'date' => $customDate ?? date('Y-m-d'),
            ]);
        } catch (\Exception $e) {
            Log::error("Error en getTrm endpoint: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'trm' => 4000.0, // Valor por defecto en caso de error crítico
                'date' => $customDate ?? date('Y-m-d'),
                'error' => 'Error obteniendo TRM',
            ], 500);
        }
    }


    /**
     * Send purchase order emails (pedido and despacho)
     */
    private function sendPurchaseOrderEmails(PurchaseOrder $purchaseOrder, array $validated, string $processTypeContext = 'purchase_order'): void
    {
        Log::info('=== INICIO ENVÍO DE EMAILS ===', [
            'order_id' => $purchaseOrder->id,
            'context' => $processTypeContext,
            'tag_email_pedidos' => $validated['tag_email_pedidos'] ?? 'vacío',
            'tag_email_despachos' => $validated['tag_email_despachos'] ?? 'vacío',
            'is_new_win' => (int) $purchaseOrder->is_new_win,
        ]);

        // Reload with relationships
        $purchaseOrder->load(['client', 'products', 'comments']);

        Log::info('EMAIL PRODUCTS FLAGS', [
            'order_id' => $purchaseOrder->id,
            'is_new_win' => (int) $purchaseOrder->is_new_win,
            'products' => $purchaseOrder->products->map(fn($p) => [
                'id' => $p->id,
                'pivot_new_win' => $p->pivot->new_win,
            ])->toArray(),
        ]);
        
        // Metadata básica
        $metadata = [
            'order_id' => $purchaseOrder->id,
            'client_id' => $purchaseOrder->client_id,
            'client_name' => $purchaseOrder->client->client_name,
            'consecutive' => $purchaseOrder->order_consecutive,
        ];

        // Generate PDF for order
        Log::info('Generando PDF...');
        $pdfContent = $this->generatePdfOrder($purchaseOrder);
        Log::info('PDF generado exitosamente', ['size' => strlen($pdfContent)]);

        $clientEmail = $purchaseOrder->client->email;
        $executiveEmail = $purchaseOrder->client->executive_email ?? $purchaseOrder->client->executive;
        $coordinator = 'monica.castano@finearom.com';
        // Usar el adjunto ya almacenado en la orden; evitar pasar rutas temporales
        $filePath = $purchaseOrder->attachment ?? null;
        $validatedAttachment = $validated['attachment'] ?? null;
        if (empty($filePath) && is_string($validatedAttachment)) {
            $filePath = $validatedAttachment;
        }

        // ============ EMAIL PEDIDOS ============
        if (empty($validated['tag_email_pedidos'])) {
            // Si no hay tag_email_pedidos, usar Process emails
            $processEmails = Process::where('process_type', 'pedido')->pluck('email')->toArray();

            // Expandir emails separados por comas
            $expandedEmails = [];
            foreach ($processEmails as $email) {
                $emails = array_map('trim', explode(',', $email));
                $expandedEmails = array_merge($expandedEmails, $emails);
            }
            $expandedEmails = array_filter(array_unique($expandedEmails));

            // CC: process emails + executive + coordinator
            $ccEmails = array_merge($expandedEmails, [$executiveEmail, $coordinator]);

            // TO: primer email del cliente
            $toEmails = explode(',', $clientEmail);
            $primaryToEmail = array_shift($toEmails);
        } else {
            // Si hay tag_email_pedidos, usarlos
            $ccEmails = explode(',', $validated['tag_email_pedidos']);
            $primaryToEmail = array_shift($ccEmails);
        }

        // Limpiar duplicados
        $ccEmails = array_filter(array_map('trim', $ccEmails));
        $ccEmails = array_values(array_unique(array_diff($ccEmails, [$primaryToEmail])));

        Log::info('EMAIL PEDIDOS - Destinatarios preparados', [
            'to' => $primaryToEmail,
            'cc' => $ccEmails,
            'cc_count' => count($ccEmails)
        ]);

        // Enviar email de pedido
        if (!empty($primaryToEmail)) {
            try {
                // Definir sub-tipo
                $subProcess = $processTypeContext === 'purchase_order_resend' ? 'purchase_order_resend' : 'purchase_order_created';

                \Mail::to($primaryToEmail)
                    ->cc($ccEmails)
                    ->send(new \App\Mail\PurchaseOrderMail($purchaseOrder, $pdfContent, $subProcess, $metadata));

                Log::info('Email de pedido enviado', [
                    'order_id' => $purchaseOrder->id,
                    'to' => $primaryToEmail,
                    'cc' => $ccEmails
                ]);
            } catch (\Exception $e) {
                Log::error('Error enviando email de pedido', [
                    'order_id' => $purchaseOrder->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // ============ EMAIL DESPACHOS ============
        if (empty($validated['tag_email_despachos'])) {
            // Si no hay tag_email_despachos, usar Process emails
            $processEmails = Process::where('process_type', 'orden_de_compra')->pluck('email')->toArray();
            Log::info('EMAIL DESPACHOS - usaremos correos de Process (orden_de_compra)', [
                'order_id' => $purchaseOrder->id,
                'process_emails' => $processEmails,
            ]);

            // Expandir emails separados por comas
            $expandedEmails = [];
            foreach ($processEmails as $email) {
                $emails = array_map('trim', explode(',', $email));
                $expandedEmails = array_merge($expandedEmails, $emails);
            }
            $expandedEmails = array_filter(array_unique($expandedEmails));

            // CC: process emails + executive + coordinator
            $ccEmails = array_merge($expandedEmails, [$executiveEmail, $coordinator]);
        } else {
            // Si hay tag_email_despachos, usarlos
            $ccEmails = explode(',', $validated['tag_email_despachos']);
            Log::info('EMAIL DESPACHOS - usando tag_email_despachos', [
                'order_id' => $purchaseOrder->id,
                'raw_tags' => $validated['tag_email_despachos'],
            ]);
        }

        // TO: primer email de la lista
        $toEmail = array_shift($ccEmails);

        // Fallbacks si no hay destinatario principal:
        // 1) usar el correo del cliente
        // 2) usar el usuario autenticado como último recurso
        if (empty($toEmail)) {
            Log::warning('EMAIL DESPACHOS - no se encontró destinatario principal, aplicando fallback', [
                'order_id' => $purchaseOrder->id,
                'client_email' => $clientEmail,
                'auth_user' => auth()->user()?->email,
            ]);
            $clientCandidates = array_filter(array_map('trim', explode(',', (string) $clientEmail)));
            $toEmail = array_shift($clientCandidates) ?: (auth()->user()->email ?? null);
            // Reincorporar el resto de candidates a CC
            $ccEmails = array_merge($clientCandidates, $ccEmails);
        }

        // Limpiar duplicados
        $ccEmails = array_filter(array_map('trim', $ccEmails));
        $ccEmails = array_values(array_unique(array_diff($ccEmails, [$toEmail])));

        Log::info('EMAIL DESPACHOS - Destinatarios preparados', [
            'to' => $toEmail,
            'cc' => $ccEmails,
            'cc_count' => count($ccEmails),
            'has_attachment' => !empty($filePath)
        ]);

        // Enviar email de despacho
        if (!empty($toEmail)) {
            try {
                // Definir sub-tipo
                 $subProcess = $processTypeContext === 'purchase_order_resend' ? 'purchase_order_despacho_resend' : 'purchase_order_despacho_created';

                \Mail::to($toEmail)
                    ->cc($ccEmails)
                    ->send(new \App\Mail\PurchaseOrderMailDespacho($purchaseOrder, $filePath, $subProcess, $metadata));

                Log::info('Email de despacho enviado', [
                    'order_id' => $purchaseOrder->id,
                    'to' => $toEmail,
                    'cc' => $ccEmails,
                    'subject' => $purchaseOrder->subject_client
                ]);
            } catch (\Exception $e) {
                Log::error('Error enviando email de despacho', [
                    'order_id' => $purchaseOrder->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Generate PDF for purchase order
     */
    private function generatePdfOrder(PurchaseOrder $purchaseOrder): string
    {
        $logo = $this->getLogoBase64();

        $pdf = \PDF::loadView('pdf.purchase_order', compact('purchaseOrder', 'logo'));

        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'defaultFont' => 'Arial'
        ]);

        return $pdf->output();
    }

    /**
     * Get company logo as base64 for PDF embedding
     */
    private function getLogoBase64(): string
    {
        $logoPath = public_path('images/logo.png');

        if (!file_exists($logoPath)) {
            // Return a data URI for a transparent 1x1 pixel if logo doesn't exist
            return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        }

        $imageData = base64_encode(file_get_contents($logoPath));
        $mimeType = 'image/png';

        return 'data:' . $mimeType . ';base64,' . $imageData;
    }

    /**
     * Invalidar caché de análisis de clientes
     * Este método debe ser llamado cuando se actualizan datos que afectan el análisis
     */
    private function clearAnalyzeCache(): void
    {
        Cache::forever('analyze.clients.force_reload_after', now()->timestamp);
    }
}

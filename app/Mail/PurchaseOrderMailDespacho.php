<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use App\Services\EmailTemplateService;

class PurchaseOrderMailDespacho extends Mailable
{
    use Queueable, SerializesModels;

    public $purchaseOrder;
    public $pdfAttachment;
    public $processType;
    public $metadata;

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param $pdfAttachment
     * @param string $processType
     * @param array $metadata
     */
    public function __construct($purchaseOrder, $pdfAttachment, $processType = 'purchase_order_despacho', $metadata = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->pdfAttachment = $pdfAttachment;
        $this->processType = $processType;
        $this->metadata = $metadata;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // Usar mismo formato de asunto que legacy
        $subject = ($this->purchaseOrder->is_new_win == 1 ? 'NEW WIN - ' : '') . 
                   'Pedido - ' . 
                   $this->purchaseOrder->client->client_name . ' - ' . 
                   $this->purchaseOrder->client->nit . ' - ' . 
                   $this->purchaseOrder->order_consecutive;

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        return new \Illuminate\Mail\Mailables\Headers(
            text: [
                'X-Process-Type' => $this->processType,
                'X-Metadata' => json_encode($this->metadata),
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $rendered = $service->renderTemplate('purchase_order_despacho', $variables);

        return new Content(
            view: 'emails.template',
            with: $rendered
        );
    }

    /**
     * Prepare variables for template rendering
     */
    protected function prepareVariables(): array
    {
        // Subject del cliente (fallback al formato por defecto)
        $subjectClient = $this->purchaseOrder->subject_client;
        if (empty($subjectClient)) {
            $subjectClient = ($this->purchaseOrder->is_new_win == 1 ? 'NEW WIN - ' : '') .
                           'Pedido - ' .
                           $this->purchaseOrder->client->client_name . ' - ' .
                           $this->purchaseOrder->client->nit . ' - ' .
                           $this->purchaseOrder->order_consecutive;
        }

        // Comentario de la orden (si existe)
        $orderComment = '';
        $comment = $this->purchaseOrder->comments->where('type', 'order_comment')->first();
        if ($comment) {
            $orderComment = '<div>
                <p><strong>📝 Observaciones importantes:</strong></p>
                <div>' . $comment->text . '</div>
            </div>';
        }

        // Tabla de productos
        $productsTable = $this->buildProductsTable();

        // Información TRM
        $trmInfo = $this->buildTrmInfo();

        return [
            'subject_client' => $subjectClient,
            'required_delivery_date' => $this->purchaseOrder->required_delivery_date,
            'order_comment' => $orderComment,
            'products_table' => $productsTable,
            'trm_info' => $trmInfo,
        ];
    }

    /**
     * Build products table HTML
     */
    protected function buildProductsTable(): string
    {
        $html = '<div>
            <h3>📋 Detalle de Productos</h3>
            <table>
                <thead>
                    <tr>
                        <th>REFERENCIA</th>
                        <th>CÓDIGO</th>
                        <th>CANTIDAD</th>
                        <th>PRECIO U</th>
                        <th>PRECIO TOTAL</th>
                        <th>NEW WIN</th>
                        <th>LUGAR DE ENTREGA</th>
                        <th>FECHA DE DESPACHO</th>
                    </tr>
                </thead>
                <tbody>';

        $total = 0;
        foreach ($this->purchaseOrder->products as $product) {
            $effectivePrice = ($product->pivot->muestra == '1')
                ? 0
                : (($product->pivot->price > 0) ? $product->pivot->price : ($product->price ?? 0));

            $code = $product->code;
            if (strpos($code, $this->purchaseOrder->client->nit) === 0) {
                $code = substr($code, strlen($this->purchaseOrder->client->nit));
            }

            $subtotal = $product->pivot->muestra == '1' ? 0 : $effectivePrice * $product->pivot->quantity;
            $total += $subtotal;

            $html .= '<tr>
                <td><strong>' . e($product->product_name) . '</strong></td>
                <td>' . e($code) . '</td>
                <td>' . e($product->pivot->quantity) . '</td>
                <td>$' . number_format($effectivePrice, 2) . '</td>
                <td>$' . number_format($subtotal, 2) . '</td>
                <td>' . ($product->pivot->new_win == 1 ? 'Sí' : 'No') . '</td>
                <td>' . e($this->purchaseOrder->getBranchOfficeName($product)) . '</td>
                <td>' . e($product->pivot->delivery_date) . '</td>
            </tr>';
        }

        $html .= '<tr>
                <td colspan="5"></td>
                <td><strong>TOTAL</strong></td>
                <td colspan="2"><strong>$' . number_format($total, 2) . ' USD</strong></td>
            </tr>
            </tbody>
        </table>
    </div>';

        return $html;
    }

    /**
     * Build TRM info HTML
     */
    protected function buildTrmInfo(): string
    {
        if ($this->purchaseOrder->trm_updated_at) {
            return 'TRM de cliente: $' . $this->purchaseOrder->trm;
        } else {
            $date = optional($this->purchaseOrder->created_at)->format('d/m/Y');
            return 'TRM del día ' . $date . ': $' . $this->purchaseOrder->trm;
        }
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        if (!$this->pdfAttachment) {
            return [];
        }

        $fullPath = storage_path('app/public/' . $this->pdfAttachment);

        return [
            Attachment::fromPath($fullPath)
                ->as('adjunto.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

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
    public $pdfAttachments;
    public $processType;
    public $customMetadata;

    /**
     * Create a new message instance.
     *
     * @param $purchaseOrder
     * @param array $pdfAttachments
     * @param string $processType
     * @param array $customMetadata
     */
    public function __construct($purchaseOrder, $pdfAttachments = [], $processType = 'purchase_order_despacho', $customMetadata = [])
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->pdfAttachments = is_array($pdfAttachments) ? $pdfAttachments : [];
        $this->processType = $processType;
        $this->customMetadata = $customMetadata;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // Usar subject_despacho guardado en la orden
        $subject = $this->purchaseOrder->subject_despacho ?: 
                   (($this->purchaseOrder->is_new_win == 1 ? 'NEW WIN - ' : '') .
                   'Pedido - ' .
                   $this->purchaseOrder->client->client_name . ' - ' .
                   $this->purchaseOrder->client->nit . ' - ' .
                   $this->purchaseOrder->order_consecutive);

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
                'X-Metadata' => json_encode($this->customMetadata),
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
        $attachments = [];

        \Log::info('📎 PurchaseOrderMailDespacho - Preparando adjuntos', [
            'order_id' => $this->purchaseOrder->id,
            'pdf_attachments_count' => count($this->pdfAttachments),
            'pdf_attachments_paths' => $this->pdfAttachments,
        ]);

        // Adjuntar todos los PDFs del usuario si existen
        if (!empty($this->pdfAttachments)) {
            $attachedCount = 0;
            $skippedCount = 0;

            foreach ($this->pdfAttachments as $index => $attachmentPath) {
                $fullPath = storage_path('app/public/' . $attachmentPath);

                if (file_exists($fullPath)) {
                    $filename = 'adjunto-' . ($index + 1) . '.pdf';
                    $attachments[] = Attachment::fromPath($fullPath)
                        ->as($filename)
                        ->withMime('application/pdf');
                    $attachedCount++;

                    \Log::info('✅ PDF adjuntado en email despacho', [
                        'order_id' => $this->purchaseOrder->id,
                        'index' => $index,
                        'filename' => $filename,
                        'path' => $attachmentPath,
                        'full_path' => $fullPath,
                    ]);
                } else {
                    $skippedCount++;
                    \Log::warning('❌ PDF NO encontrado para email despacho', [
                        'order_id' => $this->purchaseOrder->id,
                        'index' => $index,
                        'path' => $attachmentPath,
                        'full_path' => $fullPath,
                    ]);
                }
            }

            \Log::info('📊 Resumen adjuntos email despacho', [
                'order_id' => $this->purchaseOrder->id,
                'total_attachments' => count($attachments),
                'user_pdfs_attached' => $attachedCount,
                'user_pdfs_skipped' => $skippedCount,
            ]);
        } else {
            \Log::info('ℹ️ Email despacho sin PDFs de usuario', [
                'order_id' => $this->purchaseOrder->id,
            ]);
        }

        return $attachments;
    }
}

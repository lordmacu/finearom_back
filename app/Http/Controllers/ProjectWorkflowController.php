<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectDeliverRequest;
use App\Http\Requests\Project\ProjectExternalStatusRequest;
use App\Mail\ProjectQuotationMail;
use App\Models\Project;
use App\Models\ProjectQuotationLog;
use App\Models\PurchaseOrder;
use App\Services\GoogleDriveService;
use App\Services\ProjectWorkflowService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProjectWorkflowController extends Controller
{
    public function __construct(
        private readonly ProjectWorkflowService $workflowService,
        private readonly GoogleDriveService $driveService,
    ) {
        $this->middleware('can:project external status')->only(['setExternalStatus']);
        $this->middleware('can:project deliver')->only(['deliver']);
        $this->middleware('can:project list')->only(['quotation', 'quotationPdf', 'purchaseOrders', 'timeline', 'quotationLogs']);
        $this->middleware('can:project edit')->only(['linkPurchaseOrder', 'reabrir', 'sendQuotationEmail']);
    }

    public function setExternalStatus(ProjectExternalStatusRequest $request, Project $project): JsonResponse
    {
        $this->workflowService->setExternalStatus($project, $request->status, auth()->user()->name, $request->razon_perdida);

        return response()->json([
            'success' => true,
            'data'    => $project->fresh(),
            'message' => 'Estado actualizado',
        ]);
    }

    public function deliver(ProjectDeliverRequest $request, Project $project): JsonResponse
    {
        $this->workflowService->deliver($project, $request->department, auth()->user()->name);

        return response()->json([
            'success' => true,
            'data'    => $project->fresh(),
            'message' => 'Entregado correctamente',
        ]);
    }

    public function quotation(Project $project): JsonResponse
    {
        $items = match ($project->tipo) {
            'Colección'      => $project->requests()->with('fragrance')->get(),
            'Desarrollo'     => $project->variants()->with('proposals.finearomReference')->get(),
            'Fine Fragances' => $project->fragrances()->with('fineFragrance.house')->get(),
            default          => collect(),
        };

        return response()->json([
            'success' => true,
            'data'    => [
                'project' => $project->load('client'),
                'items'   => $items,
            ],
        ]);
    }

    public function quotationPdf(Project $project): Response
    {
        $project->load('client', 'product');

        $items = match ($project->tipo) {
            'Colección'      => $project->requests()->with('fragrance')->get(),
            'Desarrollo'     => $project->variants()->with('proposals.finearomReference')->get(),
            'Fine Fragances' => $project->fragrances()->with('fineFragrance.house')->get(),
            default          => collect(),
        };

        $version = ($project->quotationLogs()->max('version') ?? 0) + 1;

        ProjectQuotationLog::create([
            'project_id' => $project->id,
            'version'    => $version,
            'enviado_a'  => $project->client?->email ?? $project->email_prospecto,
            'ejecutivo'  => auth()->user()->name,
        ]);

        $pdf = Pdf::loadView('pdf.project-quotation', [
            'project' => $project,
            'items'   => $items,
        ])->setPaper('a4', 'portrait');

        $filename = "cotizacion_{$project->id}_v{$version}.pdf";

        // Subir a Google Drive en modo silencioso
        try {
            $userId = auth()->id();
            if ($this->driveService->hasDriveAccess($userId)) {
                $projectName = $project->nombre ?? "Proyecto {$project->id}";
                $folder = $this->driveService->getOrCreateProjectFolder($userId, $projectName);
                if ($folder) {
                    $this->driveService->uploadFile(
                        $userId,
                        $pdf->output(),
                        $filename,
                        $folder,
                        'application/pdf'
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning("GoogleDrive: fallo al subir cotización de proyecto {$project->id}: " . $e->getMessage());
        }

        return $pdf->download($filename);
    }

    public function sendQuotationEmail(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|email|max:200',
        ]);

        $project->load('client', 'product');

        $recipientEmail = $request->email
            ?? $project->client?->email
            ?? $project->email_prospecto;

        if (!$recipientEmail) {
            return response()->json([
                'success' => false,
                'message' => 'No hay email de destinatario. Ingresa uno manualmente.',
            ], 422);
        }

        $items = match ($project->tipo) {
            'Colección'      => $project->requests()->with('fragrance')->get(),
            'Desarrollo'     => $project->variants()->with('proposals.finearomReference')->get(),
            'Fine Fragances' => $project->fragrances()->with('fineFragrance.house')->get(),
            default          => collect(),
        };

        $version = ($project->quotationLogs()->max('version') ?? 0) + 1;

        $pdfContent = Pdf::loadView('pdf.project-quotation', [
            'project' => $project,
            'items'   => $items,
        ])->setPaper('a4', 'portrait')->output();

        Mail::to($recipientEmail)->send(new ProjectQuotationMail($project, $pdfContent, $version));

        ProjectQuotationLog::create([
            'project_id' => $project->id,
            'version'    => $version,
            'enviado_a'  => $recipientEmail,
            'ejecutivo'  => auth()->user()->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Cotización v{$version} enviada a {$recipientEmail}",
            'data'    => ['version' => $version, 'enviado_a' => $recipientEmail],
        ]);
    }

    public function quotationLogs(Project $project): JsonResponse
    {
        $logs = $project->quotationLogs()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    public function purchaseOrders(Project $project): JsonResponse
    {
        $orders = $project->purchaseOrders()
            ->with('client:id,client_name')
            ->orderByDesc('id')
            ->get(['id', 'client_id', 'order_consecutive', 'order_creation_date', 'status', 'project_id']);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function timeline(Project $project): JsonResponse
    {
        $history = $project->statusHistory()
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $history]);
    }

    public function reabrir(Project $project): JsonResponse
    {
        $this->workflowService->reabrir($project, auth()->user()->name);

        return response()->json([
            'success' => true,
            'data'    => $project->fresh(),
            'message' => 'Proyecto reabierto',
        ]);
    }

    public function linkPurchaseOrder(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'purchase_order_id' => 'required|integer|exists:purchase_orders,id',
        ]);

        $order = PurchaseOrder::findOrFail($request->purchase_order_id);
        $order->update(['project_id' => $project->id]);

        return response()->json([
            'success' => true,
            'data'    => $order->fresh('client:id,client_name'),
            'message' => 'Orden vinculada al proyecto',
        ]);
    }
}

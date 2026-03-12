<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PurchaseOrderAuthController;
use App\Http\Controllers\Api\PurchaseOrderPortalController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\CarteraController;
use App\Http\Controllers\CarteraEstadoController;
use App\Http\Controllers\EmailCampaignController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\FinearomEvaluationController;
use App\Http\Controllers\FinearomReferenceController;
use App\Http\Controllers\FineFragranceController;
use App\Http\Controllers\FineFragranceHouseController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProcessEmailController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Uploads\CkeditorUploadController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseOrderExportController;
use App\Http\Controllers\PurchaseOrderProductExportController;
use App\Http\Controllers\PurchaseOrderImportController;
use App\Http\Controllers\RecaudoImportController;
use App\Http\Controllers\ProformaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IaForecastController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectFileController;
use App\Http\Controllers\ProjectWorkflowController;
use App\Http\Controllers\ProjectCatalogController;
use App\Http\Controllers\ProjectDetailController;
use App\Http\Controllers\ProjectNotificationController;
use App\Http\Controllers\ProjectTimesController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GoogleTaskController;
use App\Http\Controllers\ProjectGoogleTaskConfigController;
use App\Http\Controllers\OrderGoogleTaskConfigController;
use App\Http\Controllers\CorazonFormulaController;
use App\Http\Controllers\RawMaterialController;
use App\Http\Controllers\ReferenceFormulaController;
use App\Http\Controllers\ContributionMarginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health Check para Docker
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'database' => 'connected'
    ]);
});

// Rutas públicas (sin autenticación)
Route::post('/login', [AuthController::class, 'login']);
Route::get('/auth/google/login-url', [GoogleAuthController::class, 'loginUrl']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/email-campaigns/track-open/{logId}', [EmailCampaignController::class, 'trackOpen']);
Route::get('/clients/public/{token}', [ClientController::class, 'showByToken']);
Route::post('/clients/public/{token}', [ClientController::class, 'updateByToken']);
Route::post('/purchase-order-portal/send-code', [PurchaseOrderAuthController::class, 'sendCode']);
Route::post('/purchase-order-portal/verify-code', [PurchaseOrderAuthController::class, 'verifyCode']);

// Rutas protegidas (requieren autenticación)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Google Tasks — OAuth + crear tareas
    Route::prefix('auth/google')->group(function () {
        Route::get('/url',             [GoogleAuthController::class, 'authUrl']);
        Route::get('/url-full',        [GoogleAuthController::class, 'authUrlFull']);
        Route::get('/status',          [GoogleAuthController::class, 'status']);
        Route::get('/status-extended', [GoogleAuthController::class, 'statusExtended']);
        Route::get('/connected-users', [GoogleAuthController::class, 'connectedUsers']);
        Route::delete('/disconnect',   [GoogleAuthController::class, 'disconnect']);
    });
    Route::post('/projects/{project}/google-task',        [GoogleTaskController::class, 'createFromProject']);
    Route::get('/projects/{project}/google-task-config',  [ProjectGoogleTaskConfigController::class, 'show']);
    Route::put('/projects/{project}/google-task-config',  [ProjectGoogleTaskConfigController::class, 'update']);
    Route::get('/order-google-task-config',  [OrderGoogleTaskConfigController::class, 'index']);
    Route::put('/order-google-task-config',  [OrderGoogleTaskConfigController::class, 'update']);

    // Visitas a clientes
    Route::apiResource('client-visits', \App\Http\Controllers\ClientVisitController::class);
    Route::post('client-visits/{clientVisit}/commitments',                    [\App\Http\Controllers\ClientVisitController::class, 'addCommitment']);
    Route::put('client-visits/{clientVisit}/commitments/{commitment}',        [\App\Http\Controllers\ClientVisitController::class, 'updateCommitment']);
    Route::delete('client-visits/{clientVisit}/commitments/{commitment}',     [\App\Http\Controllers\ClientVisitController::class, 'destroyCommitment']);
    Route::post('client-visits/{clientVisit}/gmail-draft',                   [\App\Http\Controllers\ClientVisitController::class, 'createGmailDraft']);

    // Dashboard Stats
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/export-planned-stats', [DashboardController::class, 'exportPlannedStats']);
    Route::get('/dashboard/client-stats', [DashboardController::class, 'clientQuickStats']);


    // Cuenta del usuario autenticado
    Route::get('/account', [AccountController::class, 'show']);

    // Email Tracking
    // Email Tracking
    Route::get('/email-tracking/logs', [App\Http\Controllers\EmailTrackingController::class, 'index']);
    Route::get('/email-tracking/process-types', [App\Http\Controllers\EmailTrackingController::class, 'getProcessTypes']);
    Route::get('/email-tracking/stats', [App\Http\Controllers\EmailTrackingController::class, 'stats']);
    Route::get('/email-tracking/stats', [App\Http\Controllers\EmailTrackingController::class, 'stats']);
    Route::put('/account', [AccountController::class, 'update']);
    Route::put('/account/password', [AccountController::class, 'changePassword']);

    // Rutas de Permisos
    Route::apiResource('permissions', PermissionController::class);

    // Rutas de Roles
    Route::get('roles/permissions', [RoleController::class, 'permissions']);
    Route::apiResource('roles', RoleController::class);

    // Rutas de Usuarios
    Route::apiResource('users', UserController::class);

    // Uploads (para CKEditor y otros editores)
    Route::post('/uploads/ckeditor', [CkeditorUploadController::class, 'store']);

    // Email Templates
    Route::get('/email-templates/by-key/{key}', [EmailTemplateController::class, 'getByKey']);
    Route::get('/email-templates/{id}/preview', [EmailTemplateController::class, 'getPreview']);
    Route::post('/email-templates/{id}/preview', [EmailTemplateController::class, 'sendPreview']);
    Route::apiResource('email-templates', EmailTemplateController::class);

    // Email Campaigns
    Route::get('/email-campaigns/email-fields', [EmailCampaignController::class, 'emailFields']);
    Route::get('/email-campaigns/clients', [EmailCampaignController::class, 'clients']);
    Route::post('/email-campaigns/send-test', [EmailCampaignController::class, 'sendTestEmail']);
    Route::post('/email-campaigns/{id}/send', [EmailCampaignController::class, 'send']);
    Route::post('/email-campaigns/{id}/clone', [EmailCampaignController::class, 'clone']);
    Route::post('/email-campaigns/{campaignId}/logs/{logId}/resend', [EmailCampaignController::class, 'resend']);
    Route::post('/email-campaigns/{campaignId}/logs/{logId}/resend-custom', [EmailCampaignController::class, 'resendCustom']);
    Route::put('/email-campaigns/{campaignId}/logs/{logId}/email', [EmailCampaignController::class, 'updateLogEmail']);
    Route::apiResource('email-campaigns', EmailCampaignController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // Categorías de productos
    Route::get('/product-categories/{id}/products-count', [ProductCategoryController::class, 'productsCount']);
    Route::apiResource('product-categories', ProductCategoryController::class)->only(['index', 'store', 'update', 'destroy']);

    // Configuración del sistema (Admin)
    Route::get('/settings/admin-configuration', [SettingsController::class, 'adminConfiguration']);
    Route::put('/settings/processes', [SettingsController::class, 'updateProcesses']);
    Route::put('/settings/template-pedido', [SettingsController::class, 'updateTemplatePedido']);
    Route::get('/settings/backups', [SettingsController::class, 'listBackups']);
    Route::post('/settings/backups', [SettingsController::class, 'createBackup']);
    Route::post('/settings/backups/restore', [SettingsController::class, 'restoreBackup']);

    // Analyze (clientes / parciales)
    Route::get('/analyze/clients', [AnalyzeController::class, 'clients']);
    Route::post('/analyze/clients/clear-cache', [AnalyzeController::class, 'clearAnalyzeCache']);
    Route::get('/analyze/clients/{clientId}/partials', [AnalyzeController::class, 'clientPartials']);
    Route::put('/analyze/partials/{partialId}', [AnalyzeController::class, 'updatePartial']);
    Route::delete('/analyze/partials/{partialId}', [AnalyzeController::class, 'deletePartial']);

    // Cartera
    Route::get('/cartera/summary', [CarteraController::class, 'summary']);
    Route::get('/cartera/clients', [CarteraController::class, 'clients']);
    Route::get('/cartera/executives', [CarteraController::class, 'executives']);
    Route::get('/cartera/customers', [CarteraController::class, 'customers']);
    Route::get('/cartera/invoice-history', [CarteraController::class, 'invoiceHistory']);
    Route::post('/cartera/import', [CarteraController::class, 'import']);
    Route::post('/cartera/store', [CarteraController::class, 'store']);

    // Cartera / Estado (envío a cola)
    Route::get('/cartera/estado', [CarteraEstadoController::class, 'load']);
    Route::post('/cartera/estado/queue', [CarteraEstadoController::class, 'queue']);

    // Proforma
    Route::get('/proforma/template', [ProformaController::class, 'downloadTemplate']);
    Route::post('/proforma/upload', [ProformaController::class, 'upload']);

    // Portal de Ordenes de Compra (cliente)
    Route::get('/purchase-order-portal/metadata', [PurchaseOrderPortalController::class, 'metadata']);
    Route::post('/purchase-order-portal/orders', [PurchaseOrderPortalController::class, 'store']);

    // Productos
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/search', [ProductController::class, 'search']);
    Route::get('/products/new-win-check', [ProductController::class, 'newWinCheck']);
    Route::get('/products/export', [ProductController::class, 'export']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{productId}', [ProductController::class, 'update']);
    Route::delete('/products/{productId}', [ProductController::class, 'destroy']);
    Route::post('/products/import', [ProductController::class, 'import']);
    Route::post('/products/import-price-history', [ProductController::class, 'importPriceHistory']);
    Route::post('/products/import-current-prices', [ProductController::class, 'importCurrentPrices']);
    Route::get('/products/{productId}/price-history', [ProductController::class, 'priceHistory']);
    Route::put('/products/{productId}/price-history/{historyId}', [ProductController::class, 'updatePriceHistory']);
    Route::delete('/products/{productId}/price-history/{historyId}', [ProductController::class, 'deletePriceHistory']);

    // Órdenes de compra
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/export', [PurchaseOrderExportController::class, 'export']);
    Route::get('/purchase-orders/products/export', [PurchaseOrderProductExportController::class, 'export']);
    Route::get('/purchase-orders/get-trm', [PurchaseOrderController::class, 'getTrm']);
    Route::get('/purchase-orders/php-config', [PurchaseOrderController::class, 'getPhpConfig']);
    Route::post('/purchase-orders/import', [PurchaseOrderImportController::class, 'import']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::post('/purchase-orders/{id}/resend', [PurchaseOrderController::class, 'resendOrder']);
    Route::put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update']);
    Route::delete('/purchase-orders/{id}', [PurchaseOrderController::class, 'destroy']);
    Route::delete('/purchase-orders/{id}/attachment', [PurchaseOrderController::class, 'deleteAttachment']);
    Route::get('/purchase-orders/{id}/pdf', [PurchaseOrderController::class, 'downloadPdf']);
    Route::get('/purchase-orders/{id}/proforma', [PurchaseOrderController::class, 'downloadProforma']);
    Route::post('/purchase-orders/{id}/proforma/resend', [PurchaseOrderController::class, 'resendProforma']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders/{id}/update-status', [PurchaseOrderController::class, 'updateStatus']);
    Route::post('/purchase-orders/{id}/observations', [PurchaseOrderController::class, 'updateObservations']);
    Route::post('/recaudo/import', [RecaudoImportController::class, 'import']);
    Route::get('/recaudo/recent', [RecaudoImportController::class, 'recent']);

    // Clientes y sucursales
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients/import', [ClientController::class, 'importClients']);
    Route::post('/clients/import-lead-time', [ClientController::class, 'importLeadTime']);
    Route::get('/clients/export', [ClientController::class, 'exportClients']);
    Route::get('/clients/{clientId}', [ClientController::class, 'show']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{clientId}', [ClientController::class, 'update']);
    Route::delete('/clients/{clientId}', [ClientController::class, 'destroy']);
    Route::get('/clients/{clientId}/autofill-link', [ClientController::class, 'generateAutofillLink']);
    Route::post('/clients/{clientId}/autofill/send', [ClientController::class, 'sendAutofillEmail']);
    Route::post('/clients/autofill', [ClientController::class, 'bulkAutofill']);

    Route::get('/clients/{clientId}/branch-offices', [ClientController::class, 'branchOffices']);
    Route::post('/clients/{clientId}/branch-offices', [ClientController::class, 'saveBranchOffice']);
    Route::put('/clients/{clientId}/branch-offices/{officeId}', [ClientController::class, 'updateBranchOffice']);
    Route::delete('/clients/{clientId}/branch-offices/{officeId}', [ClientController::class, 'deleteBranchOffice']);
    Route::post('/branch-offices/import', [ClientController::class, 'importOffices']);
    Route::get('/branch-offices/export', [ClientController::class, 'exportOffices']);

    Route::post('/clients/autocreation', [ClientController::class, 'autocreation']);
    Route::get('/clients/{client}/projects', [ProjectController::class, 'byClient']);

    // Ejecutivos
    Route::get('/executives', [ClientController::class, 'executives']);
    Route::post('/executives', [ClientController::class, 'saveExecutive']);
    Route::put('/executives/{executiveId}', [ClientController::class, 'updateExecutive']);
    Route::delete('/executives/{executiveId}', [ClientController::class, 'deleteExecutive']);

    // Process emails para formularios de órdenes de compra
    Route::get('/process/emails', [ProcessEmailController::class, 'getEmailsByType']);

    // IA Forecast
    Route::prefix('ia/forecast')->group(function () {
        Route::get('batch-processing', [IaForecastController::class, 'batchProcessing']);
        Route::post('analyze-all', [IaForecastController::class, 'analyzeAll']);
        Route::post('force-restart-all', [IaForecastController::class, 'forceRestartAll']);
        Route::post('retry-errors', [IaForecastController::class, 'retryErrors']);
        Route::get('clients', [IaForecastController::class, 'clients']);
        Route::get('clients/{clientId}/products', [IaForecastController::class, 'products']);
        Route::get('clients/{clientId}/processing', [IaForecastController::class, 'processing']);
        Route::post('clients/{clientId}/analyze', [IaForecastController::class, 'analyzeClient']);
        Route::get('clients/{clientId}/products/{productoId}', [IaForecastController::class, 'show']);
        Route::post('clients/{clientId}/products/{productoId}/analyze', [IaForecastController::class, 'analyze']);
    });

    // ============================================================================
    // PROYECTOS
    // ============================================================================
    Route::get('/projects/export', [ProjectController::class, 'export']);
    Route::get('/projects/dashboard', [ProjectController::class, 'dashboard']);
    Route::get('/projects/kpi-stats', [ProjectController::class, 'kpiStats']);
    Route::get('/projects/ejecutivos', [ProjectController::class, 'ejecutivos']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

    Route::post('/projects/{project}/duplicate', [ProjectController::class, 'duplicate']);
    Route::patch('/projects/{project}/link-client', [ProjectController::class, 'linkClient']);
    Route::patch('/projects/{project}/estado-externo', [ProjectWorkflowController::class, 'setExternalStatus']);
    Route::patch('/projects/{project}/entregar', [ProjectWorkflowController::class, 'deliver']);
    Route::get('/projects/{project}/cotizacion', [ProjectWorkflowController::class, 'quotation']);
    Route::get('/projects/{project}/cotizacion/pdf', [ProjectWorkflowController::class, 'quotationPdf']);
    Route::get('/projects/{project}/cotizacion/logs', [ProjectWorkflowController::class, 'quotationLogs']);
    Route::post('/projects/{project}/cotizacion/email', [ProjectWorkflowController::class, 'sendQuotationEmail']);
    Route::get('/projects/{project}/purchase-orders', [ProjectWorkflowController::class, 'purchaseOrders']);
    Route::patch('/projects/{project}/link-order', [ProjectWorkflowController::class, 'linkPurchaseOrder']);
    Route::get('/projects/{project}/timeline', [ProjectWorkflowController::class, 'timeline']);
    Route::patch('/projects/{project}/reabrir', [ProjectWorkflowController::class, 'reabrir']);

    // Adjuntos de archivos del proyecto
    Route::get('/projects/{project}/files', [ProjectFileController::class, 'index']);
    Route::post('/projects/{project}/files', [ProjectFileController::class, 'store']);
    Route::delete('/projects/{project}/files/{file}', [ProjectFileController::class, 'destroy']);
    Route::get('/projects/{project}/files/{file}/download', [ProjectFileController::class, 'download']);

    // Subentidades del proyecto (1:1)
    Route::put('/projects/{project}/sample', [ProjectDetailController::class, 'updateSample']);
    Route::put('/projects/{project}/application', [ProjectDetailController::class, 'updateApplication']);
    Route::put('/projects/{project}/evaluation', [ProjectDetailController::class, 'updateEvaluation']);
    Route::put('/projects/{project}/marketing', [ProjectDetailController::class, 'updateMarketing']);

    // Observaciones departamentales
    Route::patch('/projects/{project}/observaciones', [ProjectDetailController::class, 'updateObservaciones']);

    // Toggle actualizado
    Route::patch('/projects/{project}/actualizado', [ProjectDetailController::class, 'toggleActualizado']);

    // Cambiar factor + recalcular propuestas
    Route::patch('/projects/{project}/factor', [ProjectDetailController::class, 'updateFactor']);

    // Variantes del proyecto (solo Desarrollo)
    Route::post('/projects/{project}/variants', [ProjectDetailController::class, 'storeVariant']);
    Route::put('/projects/{project}/variants/{variant}', [ProjectDetailController::class, 'updateVariant']);
    Route::delete('/projects/{project}/variants/{variant}', [ProjectDetailController::class, 'destroyVariant']);

    // Propuestas (por variante)
    Route::post('/projects/{project}/variants/{variant}/proposals', [ProjectDetailController::class, 'storeProposal']);
    Route::put('/projects/{project}/variants/{variant}/proposals/{proposal}', [ProjectDetailController::class, 'updateProposal']);
    Route::delete('/projects/{project}/variants/{variant}/proposals/{proposal}', [ProjectDetailController::class, 'destroyProposal']);
    Route::patch('/projects/{project}/variants/{variant}/proposals/{proposal}/definitiva', [ProjectDetailController::class, 'setDefinitiva']);

    // Solicitudes de fragancia (Colección)
    Route::post('/projects/{project}/requests', [ProjectDetailController::class, 'storeRequest']);
    Route::put('/projects/{project}/requests/{projectRequest}', [ProjectDetailController::class, 'updateRequest']);
    Route::delete('/projects/{project}/requests/{projectRequest}', [ProjectDetailController::class, 'destroyRequest']);

    // Fragancias finas (Fine Fragances)
    Route::post('/projects/{project}/fragrances', [ProjectDetailController::class, 'storeProjectFragrance']);
    Route::put('/projects/{project}/fragrances/{projectFragrance}', [ProjectDetailController::class, 'updateProjectFragrance']);
    Route::delete('/projects/{project}/fragrances/{projectFragrance}', [ProjectDetailController::class, 'destroyProjectFragrance']);

    // Tiempos de lookup (para cálculo de fecha_calculada)
    Route::get('/project-times/samples', [ProjectTimesController::class, 'samples']);
    Route::put('/project-times/samples/{timeSample}', [ProjectTimesController::class, 'updateSample']);
    Route::get('/project-times/applications', [ProjectTimesController::class, 'applications']);
    Route::put('/project-times/applications/{timeApplication}', [ProjectTimesController::class, 'updateApplication']);
    Route::get('/project-times/evaluations', [ProjectTimesController::class, 'evaluations']);
    Route::put('/project-times/evaluations/{timeEvaluation}', [ProjectTimesController::class, 'updateEvaluation']);
    Route::get('/project-times/marketing', [ProjectTimesController::class, 'marketing']);
    Route::put('/project-times/marketing/{timeMarketing}', [ProjectTimesController::class, 'updateMarketing']);
    Route::get('/project-times/quality', [ProjectTimesController::class, 'quality']);
    Route::put('/project-times/quality/{timeQuality}', [ProjectTimesController::class, 'updateQuality']);
    Route::get('/project-times/responses', [ProjectTimesController::class, 'responses']);
    Route::put('/project-times/responses/{timeResponse}', [ProjectTimesController::class, 'updateResponse']);
    Route::get('/project-times/homologations', [ProjectTimesController::class, 'homologations']);
    Route::put('/project-times/homologations/{timeHomologation}', [ProjectTimesController::class, 'updateHomologation']);
    Route::get('/project-times/fine', [ProjectTimesController::class, 'fine']);
    Route::put('/project-times/fine/{timeFine}', [ProjectTimesController::class, 'updateFine']);
    Route::get('/project-times/group-classifications', [ProjectTimesController::class, 'groupClassifications']);
    Route::put('/project-times/group-classifications/{groupClassification}', [ProjectTimesController::class, 'updateGroupClassification']);

    // Project times — store & destroy
    Route::post('/project-times/samples', [ProjectTimesController::class, 'storeSample']);
    Route::delete('/project-times/samples/{timeSample}', [ProjectTimesController::class, 'destroySample']);
    Route::post('/project-times/applications', [ProjectTimesController::class, 'storeApplication']);
    Route::delete('/project-times/applications/{timeApplication}', [ProjectTimesController::class, 'destroyApplication']);
    Route::post('/project-times/evaluations', [ProjectTimesController::class, 'storeEvaluation']);
    Route::delete('/project-times/evaluations/{timeEvaluation}', [ProjectTimesController::class, 'destroyEvaluation']);
    Route::post('/project-times/marketing', [ProjectTimesController::class, 'storeMarketing']);
    Route::delete('/project-times/marketing/{timeMarketing}', [ProjectTimesController::class, 'destroyMarketing']);
    Route::post('/project-times/quality', [ProjectTimesController::class, 'storeQuality']);
    Route::delete('/project-times/quality/{timeQuality}', [ProjectTimesController::class, 'destroyQuality']);
    Route::post('/project-times/responses', [ProjectTimesController::class, 'storeResponse']);
    Route::delete('/project-times/responses/{timeResponse}', [ProjectTimesController::class, 'destroyResponse']);
    Route::post('/project-times/homologations', [ProjectTimesController::class, 'storeHomologation']);
    Route::delete('/project-times/homologations/{timeHomologation}', [ProjectTimesController::class, 'destroyHomologation']);
    Route::post('/project-times/fine', [ProjectTimesController::class, 'storeFine']);
    Route::delete('/project-times/fine/{timeFine}', [ProjectTimesController::class, 'destroyFine']);
    Route::post('/project-times/group-classifications', [ProjectTimesController::class, 'storeGroupClassification']);
    Route::delete('/project-times/group-classifications/{groupClassification}', [ProjectTimesController::class, 'destroyGroupClassification']);

    // Catálogos de proyecto — CRUD completo
    Route::get('/project-catalogs/product-categories', [ProjectCatalogController::class, 'productCategories']);
    Route::get('/project-catalogs/product-types', [ProjectCatalogController::class, 'projectProductTypes']);
    Route::post('/project-catalogs/product-types', [ProjectCatalogController::class, 'storeProductType']);
    Route::put('/project-catalogs/product-types/{projectProductType}', [ProjectCatalogController::class, 'updateProductType']);
    Route::delete('/project-catalogs/product-types/{projectProductType}', [ProjectCatalogController::class, 'destroyProductType']);

    Route::get('/project-catalogs/fragrances', [ProjectCatalogController::class, 'fragrances']);
    Route::post('/project-catalogs/fragrances', [ProjectCatalogController::class, 'storeFragrance']);
    Route::put('/project-catalogs/fragrances/{fragrance}', [ProjectCatalogController::class, 'updateFragrance']);
    Route::delete('/project-catalogs/fragrances/{fragrance}', [ProjectCatalogController::class, 'destroyFragrance']);

    Route::get('/project-catalogs/fine-fragrances', [ProjectCatalogController::class, 'fineFragrances']);
    Route::post('/project-catalogs/fine-fragrances', [ProjectCatalogController::class, 'storeFineFragrance']);
    Route::put('/project-catalogs/fine-fragrances/{fineFragrance}', [ProjectCatalogController::class, 'updateFineFragrance']);
    Route::delete('/project-catalogs/fine-fragrances/{fineFragrance}', [ProjectCatalogController::class, 'destroyFineFragrance']);

    Route::get('/project-catalogs/houses', [ProjectCatalogController::class, 'houses']);
    Route::post('/project-catalogs/houses', [ProjectCatalogController::class, 'storeHouse']);
    Route::put('/project-catalogs/houses/{house}', [ProjectCatalogController::class, 'updateHouse']);
    Route::delete('/project-catalogs/houses/{house}', [ProjectCatalogController::class, 'destroyHouse']);

    Route::get('/project-catalogs/families', [ProjectCatalogController::class, 'families']);
    Route::post('/project-catalogs/families', [ProjectCatalogController::class, 'storeFamily']);
    Route::put('/project-catalogs/families/{family}', [ProjectCatalogController::class, 'updateFamily']);
    Route::delete('/project-catalogs/families/{family}', [ProjectCatalogController::class, 'destroyFamily']);

    Route::get('/project-catalogs/finearom-references', [ProjectCatalogController::class, 'finearomReferences']);
    Route::post('/project-catalogs/finearom-references', [ProjectCatalogController::class, 'storeFinearomReference']);
    Route::put('/project-catalogs/finearom-references/{finearomReference}', [ProjectCatalogController::class, 'updateFinearomReference']);
    Route::delete('/project-catalogs/finearom-references/{finearomReference}', [ProjectCatalogController::class, 'destroyFinearomReference']);
    Route::get('/project-catalogs/finearom-references/{finearomReference}/price-history', [ProjectCatalogController::class, 'finearomPriceHistory']);

    // Notificaciones internas de proyectos
    Route::get('/project-notifications', [ProjectNotificationController::class, 'index']);
    Route::get('/project-notifications/unread-count', [ProjectNotificationController::class, 'unreadCount']);
    Route::patch('/project-notifications/{notification}/read', [ProjectNotificationController::class, 'markRead']);
    Route::patch('/project-notifications/mark-all-read', [ProjectNotificationController::class, 'markAllRead']);

    // ============================================================================
    // FINEAROM REFERENCES — Top Calificadas
    // ============================================================================
    Route::apiResource('finearom-references', FinearomReferenceController::class);
    Route::post('finearom-references/{finearomReference}/update-price', [FinearomReferenceController::class, 'updatePrice']);
    Route::get('finearom-references/{finearomReference}/price-history', [FinearomReferenceController::class, 'priceHistory']);
    Route::post('finearom-references/{finearomReference}/evaluations', [FinearomEvaluationController::class, 'store']);
    Route::put('finearom-references/{finearomReference}/evaluations/{finearomEvaluation}', [FinearomEvaluationController::class, 'update']);
    Route::delete('finearom-references/{finearomReference}/evaluations/{finearomEvaluation}', [FinearomEvaluationController::class, 'destroy']);

    // ============================================================================
    // MATERIAS PRIMAS
    // ============================================================================
    Route::apiResource('raw-materials', RawMaterialController::class);
    Route::post('raw-materials/{rawMaterial}/update-cost', [RawMaterialController::class, 'updateCost']);
    Route::post('raw-materials/{rawMaterial}/movements', [RawMaterialController::class, 'addMovement']);

    // Formula lines de referencias Finearom
    Route::get('finearom-references/{finearomReference}/formula', [ReferenceFormulaController::class, 'index']);
    Route::post('finearom-references/{finearomReference}/formula', [ReferenceFormulaController::class, 'store']);
    Route::delete('finearom-references/{finearomReference}/formula/{referenceFormulaLine}', [ReferenceFormulaController::class, 'destroy']);

    // Formula interna de corazones (sub-ingredientes)
    Route::get('raw-materials/{rawMaterial}/corazon-formula', [CorazonFormulaController::class, 'index']);
    Route::post('raw-materials/{rawMaterial}/corazon-formula', [CorazonFormulaController::class, 'store']);
    Route::delete('raw-materials/{rawMaterial}/corazon-formula/{corazonFormulaLine}', [CorazonFormulaController::class, 'destroy']);

    // ============================================================================
    // FINE FRAGRANCES
    // ============================================================================
    Route::apiResource('fine-fragrance-houses', FineFragranceHouseController::class);
    Route::post('fine-fragrances/{fineFragrance}/update-price', [FineFragranceController::class, 'updatePrice']);
    Route::post('fine-fragrances/{fineFragrance}/add-inventory', [FineFragranceController::class, 'addInventory']);
    Route::post('fine-fragrances/{fineFragrance}/upload-photo', [FineFragranceController::class, 'uploadPhoto']);
    Route::post('fine-fragrances/import', [FineFragranceController::class, 'import']);
    Route::apiResource('fine-fragrances', FineFragranceController::class);

    // ============================================================================
    // MARGENES DE CONTRIBUCION
    // ============================================================================
    Route::get('contribution-margins/lookup', [ContributionMarginController::class, 'lookup']);
    Route::apiResource('contribution-margins', ContributionMarginController::class)->except(['show']);
});

// Public Pixel Route (no auth)
Route::get('/tracking/{uuid}/pixel.png', [App\Http\Controllers\EmailTrackingController::class, 'pixel']);

// =====================================================
// Siigo Sync - Rutas independientes para middleware Go
// =====================================================
use App\Http\Controllers\SiigoSyncController;

// Login propio para el middleware (no requiere auth)
Route::post('/siigo/login', [SiigoSyncController::class, 'login']);

// Reporte mensual JSON y análisis IA — públicos (sin auth)
Route::get('/dashboard/monthly-report', [\App\Http\Controllers\MonthlyReportController::class, 'index']);
Route::post('/dashboard/monthly-report/generate', [\App\Http\Controllers\MonthlyReportController::class, 'generate']);
Route::get('/dashboard/monthly-report/analyze', [\App\Http\Controllers\MonthlyReportController::class, 'analyze']);
Route::get('/dashboard/monthly-report/analyze/stream', [\App\Http\Controllers\MonthlyReportController::class, 'stream']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/dashboard/chat/start',             [\App\Http\Controllers\MonthlyReportController::class, 'chatStart']);
    Route::post('/dashboard/chat/message',           [\App\Http\Controllers\MonthlyReportController::class, 'chatMessage']);
    Route::post('/dashboard/chat/run-query',         [\App\Http\Controllers\MonthlyReportController::class, 'runQuery']);
    Route::get('/dashboard/chat/sessions',           [\App\Http\Controllers\MonthlyReportController::class, 'chatSessions']);
    Route::get('/dashboard/chat/sessions/{session}', [\App\Http\Controllers\MonthlyReportController::class, 'chatSessionMessages']);
});

// Webhook (no requiere auth Sanctum - usa HMAC signature)
Route::post('/siigo/webhook', [SiigoSyncController::class, 'webhook']);

// Rutas protegidas para sincronización (sin throttle - sync masivo)
Route::middleware('auth:sanctum')->prefix('siigo')->group(function () {
    // Endpoint generico - recibe { table, action, key, data }
    Route::post('/sync', [SiigoSyncController::class, 'sync']);

    // Endpoints por tabla (legacy, siguen funcionando)
    Route::post('/clients', [SiigoSyncController::class, 'syncClients']);
    Route::post('/products', [SiigoSyncController::class, 'syncProducts']);
    Route::post('/movements', [SiigoSyncController::class, 'syncMovements']);
    Route::post('/cartera', [SiigoSyncController::class, 'syncCartera']);
    Route::post('/bulk', [SiigoSyncController::class, 'bulk']);
    Route::get('/status', [SiigoSyncController::class, 'status']);
    Route::get('/clients', [SiigoSyncController::class, 'listClients']);
    Route::get('/products', [SiigoSyncController::class, 'listProducts']);
    Route::get('/movements', [SiigoSyncController::class, 'listMovements']);
    Route::get('/cartera', [SiigoSyncController::class, 'listCartera']);
    Route::get('/webhook-logs', [SiigoSyncController::class, 'webhookLogs']);
});

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
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProcessEmailController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Uploads\CkeditorUploadController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseOrderExportController;
use App\Http\Controllers\PurchaseOrderProductExportController;
use App\Http\Controllers\PurchaseOrderImportController;
use App\Http\Controllers\RecaudoImportController;
use App\Http\Controllers\ProformaController;
use App\Http\Controllers\DashboardController;
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
    Route::get('/products/export', [ProductController::class, 'export']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{productId}', [ProductController::class, 'update']);
    Route::delete('/products/{productId}', [ProductController::class, 'destroy']);
    Route::post('/products/import', [ProductController::class, 'import']);
    Route::get('/products/{productId}/price-history', [ProductController::class, 'priceHistory']);

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

    // Ejecutivos
    Route::get('/executives', [ClientController::class, 'executives']);
    Route::post('/executives', [ClientController::class, 'saveExecutive']);
    Route::put('/executives/{executiveId}', [ClientController::class, 'updateExecutive']);
    Route::delete('/executives/{executiveId}', [ClientController::class, 'deleteExecutive']);

    // Process emails para formularios de órdenes de compra
    Route::get('/process/emails', [ProcessEmailController::class, 'getEmailsByType']);
});

// Public Pixel Route (no auth)
Route::get('/tracking/{uuid}/pixel.png', [App\Http\Controllers\EmailTrackingController::class, 'pixel']);

<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmailCampaign\ResendCustomRequest;
use App\Http\Requests\EmailCampaign\ResendRequest;
use App\Http\Requests\EmailCampaign\SendTestEmailRequest;
use App\Http\Requests\EmailCampaign\StoreEmailCampaignRequest;
use App\Http\Requests\EmailCampaign\UpdateEmailCampaignRequest;
use App\Http\Requests\EmailCampaign\UpdateLogEmailRequest;
use App\Jobs\SendCampaignEmail;
use App\Jobs\SendCampaignEmailCustom;
use App\Mail\CampaignMail;
use App\Models\Client;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class EmailCampaignController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:email campaign list')->only(['index', 'show']);
        $this->middleware('can:email campaign create')->only(['store', 'sendTestEmail']);
        $this->middleware('can:email campaign edit')->only(['update', 'clone']);
        $this->middleware('can:email campaign delete')->only(['destroy']);
        $this->middleware('can:email campaign send')->only(['send']);
        $this->middleware('can:email campaign resend')->only(['resend', 'resendCustom', 'updateLogEmail']);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $search = (string) $request->input('search', '');
        $sortBy = (string) $request->input('sort_by', 'created_at');
        $sortDirection = (string) $request->input('sort_direction', 'desc');

        $allowedColumns = ['id', 'campaign_name', 'subject', 'status', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedColumns, true)) {
            $sortBy = 'created_at';
        }
        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc'], true)
            ? strtolower($sortDirection)
            : 'desc';

        $query = EmailCampaign::query()
            ->with('user:id,name,email')
            ->withCount([
                'logs as total_sent' => fn ($q) => $q->where('status', 'sent'),
            ])
            ->orderBy($sortBy, $sortDirection);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('campaign_name', 'LIKE', "%{$search}%")
                    ->orWhere('subject', 'LIKE', "%{$search}%");
            });
        }

        if ($request->has('per_page') && $perPage === 'all') {
            return response()->json([
                'success' => true,
                'data' => $query->get(),
            ]);
        }

        $campaigns = $query->paginate((int) $perPage);

        return response()->json([
            'success' => true,
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'from' => $campaigns->firstItem(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'to' => $campaigns->lastItem(),
                'total' => $campaigns->total(),
            ],
            'links' => [
                'first' => $campaigns->url(1),
                'last' => $campaigns->url($campaigns->lastPage()),
                'prev' => $campaigns->previousPageUrl(),
                'next' => $campaigns->nextPageUrl(),
            ],
        ]);
    }

    public function emailFields(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                ['label' => 'Email Principal', 'value' => 'email'],
                ['label' => 'Email Ejecutivo', 'value' => 'executive_email'],
                ['label' => 'Email Cartera', 'value' => 'portfolio_contact_email'],
                ['label' => 'Email Confirmación Despacho', 'value' => 'dispatch_confirmation_email'],
                ['label' => 'Email Contabilidad', 'value' => 'accounting_contact_email'],
                ['label' => 'Email Compras', 'value' => 'compras_email'],
                ['label' => 'Email Logística', 'value' => 'logistics_email'],
            ],
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $emailField = (string) $request->get('email_field', 'email');

        $allowed = [
            'email',
            'executive_email',
            'portfolio_contact_email',
            'dispatch_confirmation_email',
            'accounting_contact_email',
            'compras_email',
            'logistics_email',
        ];

        if (! in_array($emailField, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Campo de email inválido',
            ], 422);
        }

        $clients = Client::query()
            ->select(['id', 'client_name', 'nit', $emailField])
            ->whereNotNull($emailField)
            ->where($emailField, '!=', '')
            ->orderBy('client_name')
            ->get()
            ->map(function ($client) use ($emailField) {
                $emailValue = $client->{$emailField};
                $emails = [];
                if (is_string($emailValue)) {
                    $emails = array_filter(array_map('trim', explode(',', $emailValue)));
                }

                return [
                    'id' => $client->id,
                    'client_name' => $client->client_name,
                    'nit' => $client->nit,
                    'emails' => implode(', ', $emails),
                    'email_count' => count($emails),
                ];
            })
            ->filter(fn ($c) => $c['email_count'] > 0)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }

    public function store(StoreEmailCampaignRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $processedBody = $this->processInlineImages($validated['body']);

        $campaign = EmailCampaign::create([
            'campaign_name' => $validated['campaign_name'],
            'subject' => $validated['subject'],
            'email_field_type' => $validated['email_field_type'],
            'body' => $processedBody,
            'client_ids' => $validated['client_ids'],
            'custom_emails' => $validated['custom_emails'] ?? [],
            'status' => 'draft',
            'user_id' => (int) auth()->id(),
        ]);

        if ($request->hasFile('attachments')) {
            $attachmentPaths = [];
            foreach ($request->file('attachments') as $file) {
                $attachmentPaths[] = $file->store('campaign_attachments');
            }
            $campaign->attachments = $attachmentPaths;
            $campaign->save();
        }

        $campaign->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'message' => 'Campaña creada exitosamente',
            'data' => $campaign,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = EmailCampaign::with(['logs.client', 'user:id,name,email'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $campaign,
        ]);
    }

    public function update(UpdateEmailCampaignRequest $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        if ($campaign->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden editar campañas en estado borrador',
            ], 400);
        }

        $validated = $request->validated();
        $processedBody = $this->processInlineImages($validated['body']);

        $campaign->update([
            'campaign_name' => $validated['campaign_name'],
            'subject' => $validated['subject'],
            'email_field_type' => $validated['email_field_type'],
            'body' => $processedBody,
            'client_ids' => $validated['client_ids'],
            'custom_emails' => $validated['custom_emails'] ?? [],
        ]);

        if ($request->hasFile('attachments')) {
            $attachmentPaths = $campaign->attachments ?? [];
            foreach ($request->file('attachments') as $file) {
                $attachmentPaths[] = $file->store('campaign_attachments');
            }
            $campaign->attachments = $attachmentPaths;
            $campaign->save();
        }

        $campaign->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'message' => 'Campaña actualizada exitosamente',
            'data' => $campaign,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        if ($campaign->attachments) {
            foreach ($campaign->attachments as $path) {
                Storage::delete($path);
            }
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaña eliminada exitosamente',
        ]);
    }

    public function clone(int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        $cloned = $campaign->replicate();
        $cloned->campaign_name = $campaign->campaign_name . ' (Copia)';
        $cloned->status = 'draft';
        $cloned->sent_at = null;
        $cloned->sent_count = 0;
        $cloned->failed_count = 0;
        $cloned->total_recipients = 0;
        $cloned->created_at = now();
        $cloned->updated_at = now();

        if ($campaign->attachments) {
            $newAttachments = [];
            foreach ($campaign->attachments as $path) {
                if (Storage::exists($path)) {
                    $newPath = 'campaign_attachments/' . uniqid() . '_' . basename($path);
                    Storage::copy($path, $newPath);
                    $newAttachments[] = $newPath;
                }
            }
            $cloned->attachments = $newAttachments;
        }

        $cloned->save();

        return response()->json([
            'success' => true,
            'message' => 'Campaña clonada exitosamente',
            'data' => $cloned,
        ]);
    }

    public function sendTestEmail(SendTestEmailRequest $request): JsonResponse
    {
        try {
            $processedBody = $this->processInlineImages($request->validated()['body']);

            Mail::to($request->validated()['test_email'])->send(new CampaignMail(
                '[PRUEBA] ' . $request->validated()['subject'],
                $processedBody,
                $request->validated()['attachments'] ?? [],
                null
            ));

            return response()->json([
                'success' => true,
                'message' => 'Email de prueba enviado exitosamente a ' . $request->validated()['test_email'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar email de prueba: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);

        if (in_array($campaign->status, ['sent', 'sending'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Esta campaña ya fue enviada o está en proceso de envío',
            ], 400);
        }

        $campaign->status = 'sending';
        $campaign->save();

        $clients = Client::whereIn('id', $campaign->client_ids ?? [])->get();
        $totalRecipients = $clients->count();
        if (! empty($campaign->custom_emails)) {
            $totalRecipients += count($campaign->custom_emails);
        }

        $campaign->total_recipients = $totalRecipients;
        $campaign->save();

        DB::transaction(function () use ($campaign, $clients) {
            foreach ($clients as $client) {
                $emailField = $campaign->email_field_type;
                $emailValue = $client->{$emailField};

                if (empty($emailValue)) {
                    EmailCampaignLog::create([
                        'email_campaign_id' => $campaign->id,
                        'client_id' => $client->id,
                        'email_field_used' => $emailField,
                        'email_sent_to' => '',
                        'status' => 'failed',
                        'error_message' => 'No se encontró email en el campo especificado',
                    ]);
                    $campaign->increment('failed_count');
                    continue;
                }

                $emails = array_filter(array_map('trim', explode(',', (string) $emailValue)));

                $log = EmailCampaignLog::create([
                    'email_campaign_id' => $campaign->id,
                    'client_id' => $client->id,
                    'email_field_used' => $emailField,
                    'email_sent_to' => implode(',', $emails),
                    'status' => 'pending',
                ]);

                SendCampaignEmail::dispatch($campaign->id, $log->id);
            }

            if (! empty($campaign->custom_emails)) {
                foreach ($campaign->custom_emails as $customEmail) {
                    $log = EmailCampaignLog::create([
                        'email_campaign_id' => $campaign->id,
                        'client_id' => null,
                        'email_field_used' => 'custom',
                        'email_sent_to' => $customEmail,
                        'status' => 'pending',
                    ]);

                    SendCampaignEmail::dispatch($campaign->id, $log->id);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Campaña agregada a la cola de envío. Los emails se enviarán en breve.',
            'data' => [
                'total_recipients' => $campaign->total_recipients,
            ],
        ]);
    }

    public function resend(ResendRequest $request, int $campaignId, int $logId): JsonResponse
    {
        $log = EmailCampaignLog::with('campaign', 'client')->findOrFail($logId);
        $campaign = $log->campaign;
        $client = $log->client;

        $emailField = $request->validated()['email_field_type'] ?? $log->email_field_used;
        $customEmail = $request->validated()['custom_email'] ?? null;

        if ($customEmail) {
            $emails = [$customEmail];
        } else {
            $emailValue = $client?->{$emailField};
            if (empty($emailValue)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró email en el campo especificado',
                ], 400);
            }
            $emails = array_filter(array_map('trim', explode(',', (string) $emailValue)));
        }

        $log->email_sent_to = implode(',', $emails);
        $log->email_field_used = $emailField;
        $log->status = 'pending';
        $log->error_message = null;
        $log->save();

        SendCampaignEmail::dispatch($campaign->id, $log->id);

        return response()->json([
            'success' => true,
            'message' => 'Email agregado a la cola de reenvío',
            'data' => $log,
        ]);
    }

    public function updateLogEmail(UpdateLogEmailRequest $request, int $campaignId, int $logId): JsonResponse
    {
        $emails = array_filter(array_map('trim', explode(',', $request->validated()['email'])));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'success' => false,
                    'message' => "El email '{$email}' no es válido",
                ], 400);
            }
        }

        $log = EmailCampaignLog::findOrFail($logId);
        $log->email_sent_to = implode(',', $emails);
        $log->save();

        return response()->json([
            'success' => true,
            'message' => 'Email actualizado exitosamente',
            'data' => $log,
        ]);
    }

    public function resendCustom(ResendCustomRequest $request, int $campaignId, int $logId): JsonResponse
    {
        $log = EmailCampaignLog::with('campaign', 'client')->findOrFail($logId);
        $campaign = $log->campaign;

        $processedBody = $this->processInlineImages($request->validated()['body']);

        $emails = array_filter(array_map('trim', explode(',', $request->validated()['email'])));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'success' => false,
                    'message' => "El email '{$email}' no es válido",
                ], 400);
            }
        }

        $additionalAttachments = [];
        if ($request->hasFile('additional_attachments')) {
            foreach ($request->file('additional_attachments') as $file) {
                $additionalAttachments[] = $file->store('campaign_attachments', 'local');
            }
        }

        $allAttachments = array_merge($campaign->attachments ?? [], $additionalAttachments);

        $newLog = EmailCampaignLog::create([
            'email_campaign_id' => $campaign->id,
            'client_id' => $log->client_id,
            'email_field_used' => $log->email_field_used,
            'email_sent_to' => implode(',', $emails),
            'status' => 'pending',
            'error_message' => null,
        ]);

        SendCampaignEmailCustom::dispatch(
            $campaign->id,
            $newLog->id,
            $request->validated()['subject'],
            $processedBody,
            $allAttachments
        );

        return response()->json([
            'success' => true,
            'message' => 'Email agregado a la cola de reenvío',
            'data' => $newLog,
        ]);
    }

    public function trackOpen(int $logId)
    {
        $log = EmailCampaignLog::find($logId);
        if ($log) {
            if (! $log->opened_at) {
                $log->opened_at = now();
            }
            $log->open_count = ((int) ($log->open_count ?? 0)) + 1;
            $log->save();
        }

        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    private function processInlineImages(string $html): string
    {
        if (stripos($html, 'data:image') === false) {
            return $html;
        }

        $pattern = '/<img([^>]*?)src=[\"\\\']data:image\\/([^;\"\\\']+);base64,([^\"\\\']+)[\"\\\']([^>]*?)>/i';

        return (string) preg_replace_callback($pattern, function ($matches) {
            $beforeSrc = $matches[1];
            $mime = $matches[2];
            $base64Data = $matches[3];
            $afterSrc = $matches[4];

            $ext = strtolower($mime);
            if (strpos($ext, '/') !== false) {
                $parts = explode('/', $ext);
                $ext = end($parts);
            }
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                $ext = 'png';
            }

            $data = base64_decode($base64Data);
            if ($data === false) {
                return $matches[0];
            }

            $path = 'campaign_inline/' . uniqid('img_', true) . '.' . $ext;
            Storage::disk('public')->put($path, $data);
            $publicUrl = url(Storage::disk('public')->url($path));

            return '<img' . $beforeSrc . 'src="' . $publicUrl . '"' . $afterSrc . '>';
        }, $html);
    }
}


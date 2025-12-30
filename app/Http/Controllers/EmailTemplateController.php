<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $templates = EmailTemplate::all();
        return response()->json($templates);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|unique:email_templates,key|max:255',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'header_content' => 'nullable|string',
            'footer_content' => 'nullable|string',
            'signature' => 'nullable|string',
            'available_variables' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $template = EmailTemplate::create($validated);

        return response()->json($template, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);
        return response()->json($template);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);

        $validated = $request->validate([
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('email_templates', 'key')->ignore($template->id)
            ],
            'name' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'title' => 'nullable|string|max:255',
            'header_content' => 'nullable|string',
            'footer_content' => 'nullable|string',
            'signature' => 'nullable|string',
            'available_variables' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Template deleted successfully'], 200);
    }

    /**
     * Get template by key
     */
    public function getByKey(string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->where('is_active', true)->firstOrFail();
        return response()->json($template);
    }

    /**
     * Get preview HTML
     */
    public function getPreview(string $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);

        // Crear variables demo basadas en las available_variables
        $demoVariables = [];
        if ($template->available_variables) {
            foreach ($template->available_variables as $varName => $description) {
                $demoVariables[$varName] = $varName; // Usa el nombre de la variable como valor demo
            }
        }

        try {
            $service = new \App\Services\EmailTemplateService();
            $html = $service->getRenderedHtml($template->key, $demoVariables);

            return response()->json([
                'html' => $html,
                'demo_variables' => $demoVariables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar el preview',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send preview email
     */
    public function sendPreview(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $template = EmailTemplate::findOrFail($id);

        // Crear variables demo basadas en las available_variables
        $demoVariables = [];
        if ($template->available_variables) {
            foreach ($template->available_variables as $varName => $description) {
                $demoVariables[$varName] = $varName; // Usa el nombre de la variable como valor demo
            }
        }

        try {
            $service = new \App\Services\EmailTemplateService();
            $rendered = $service->renderTemplate($template->key, $demoVariables);

            // Enviar email usando Mail facade
            \Illuminate\Support\Facades\Mail::send('emails.template', $rendered, function ($message) use ($validated, $rendered) {
                $message->to($validated['email'])
                    ->subject('[PREVIEW] ' . $rendered['subject']);
            });

            return response()->json([
                'message' => 'Email de preview enviado correctamente a ' . $validated['email'],
                'demo_variables' => $demoVariables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al enviar el preview',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

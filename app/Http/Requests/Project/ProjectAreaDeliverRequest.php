<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectAreaDeliveryLog;
use App\Support\HtmlText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectAreaDeliverRequest extends FormRequest
{
    public const MAX_FILE_KB = 10240; // 10 MB por archivo

    /**
     * Tipos de adjunto aceptados (Laravel los reconoce por el contenido, no por
     * el nombre). Debe coincidir con EXTENSIONES_PERMITIDAS de ProjectAreaDeliverModal.vue.
     */
    public const TIPOS_PERMITIDOS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
        'zip', 'rar', '7z',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // parcial = el área sigue en proceso; final (por defecto) = la marca entregada
            'tipo'       => ['nullable', Rule::in([ProjectAreaDeliveryLog::PARCIAL, ProjectAreaDeliveryLog::FINAL])],
            'notas'      => ['nullable', 'string', 'max:20000'],
            'adjuntos'   => ['nullable', 'array', 'max:10'],
            'adjuntos.*' => ['file', 'max:' . self::MAX_FILE_KB, 'mimes:' . implode(',', self::TIPOS_PERMITIDOS)],
        ];
    }

    public function withValidator($validator): void
    {
        // Una entrega parcial sin notas ni archivos no aporta nada a la bitácora
        $validator->after(function ($validator) {
            if ($this->input('tipo') === ProjectAreaDeliveryLog::PARCIAL
                && HtmlText::isBlank($this->input('notas'))
                && !$this->hasFile('adjuntos')) {
                $validator->errors()->add('notas', 'La entrega parcial necesita notas o al menos un adjunto.');
            }
        });
    }

    public function esParcial(): bool
    {
        return $this->input('tipo') === ProjectAreaDeliveryLog::PARCIAL;
    }

    public function messages(): array
    {
        return [
            'adjuntos.*.mimes'    => 'Uno de los adjuntos no es de un tipo permitido. Se aceptan PDF, Word, Excel, CSV, TXT, PowerPoint, imágenes (JPG, PNG, GIF, WEBP, HEIC) y comprimidos (ZIP, RAR, 7Z).',
            'adjuntos.*.max'      => 'Uno de los adjuntos supera los 10 MB permitidos por archivo.',
            'adjuntos.*.file'     => 'Uno de los adjuntos no llegó como archivo. Vuelve a adjuntarlo.',
            'adjuntos.max'        => 'Máximo 10 archivos por entrega.',
        ];
    }
}

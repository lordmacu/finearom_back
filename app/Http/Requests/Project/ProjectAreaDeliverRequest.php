<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectAreaDeliveryLog;
use App\Support\HtmlText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectAreaDeliverRequest extends FormRequest
{
    public const MAX_FILE_KB = 10240; // 10 MB por archivo

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
            'adjuntos.*' => ['file', 'max:' . self::MAX_FILE_KB, 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip'],
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
}

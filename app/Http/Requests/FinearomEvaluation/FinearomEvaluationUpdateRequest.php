<?php

namespace App\Http\Requests\FinearomEvaluation;

use Illuminate\Foundation\Http\FormRequest;

class FinearomEvaluationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_evaluacion'      => ['sometimes', 'required', 'date'],
            'benchmarks'            => ['sometimes', 'nullable', 'array'],
            'benchmarks.*'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'puntaje_agradabilidad' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'puntaje_intensidad'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'puntaje_promedio'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'observaciones'         => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

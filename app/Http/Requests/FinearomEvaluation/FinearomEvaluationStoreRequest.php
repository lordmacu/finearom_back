<?php

namespace App\Http\Requests\FinearomEvaluation;

use Illuminate\Foundation\Http\FormRequest;

class FinearomEvaluationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_evaluacion'      => ['required', 'date'],
            'benchmarks'            => ['nullable', 'array'],
            'benchmarks.*'          => ['nullable', 'string', 'max:255'],
            'puntaje_agradabilidad' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'puntaje_intensidad'    => ['nullable', 'numeric', 'min:0', 'max:10'],
            'puntaje_promedio'      => ['nullable', 'numeric', 'min:0', 'max:10'],
            'observaciones'         => ['nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipos'                  => ['nullable', 'array'],
            'tipos.*'                => ['nullable', 'string', 'max:255'],
            'benchmark_reference_id' => ['nullable', 'integer', 'exists:finearom_references,id'],
            'metodologia'            => ['nullable', 'in:olfativa,instrumental,mixta'],
            'observacion'            => ['nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Project;

use App\Support\ProjectOwnership;
use Illuminate\Foundation\Http\FormRequest;

class ProjectEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Regla de dueño: una comercial solo edita proyectos donde es la ejecutiva
        return ProjectOwnership::canManage($this->user(), $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'tipos'                  => ['nullable', 'array'],
            'tipos.*'                => ['nullable', 'string', 'max:255'],
            'benchmark_reference_id' => ['nullable', 'integer', 'exists:finearom_references,id'],
            'metodologia'            => ['nullable', 'in:olfativa,instrumental,mixta'],
            'observacion'            => ['nullable', 'string', 'max:2000'],
            'bench_text'             => ['nullable', 'string', 'max:2000'],
            'bench_image'            => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'remove_bench_image'     => ['nullable', 'boolean'],
        ];
    }
}

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
            'bench_disponible'       => ['nullable', 'boolean'],
            'bench_listado'          => ['nullable', 'string', 'max:150'],
            'bench_image'            => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'remove_bench_image'     => ['nullable', 'boolean'],
        ];
    }

    /**
     * Si se indica que el bench NO está disponible, la imagen y la descripción son obligatorias
     * (con ellas se pide la compra del bench por correo). Solo aplica cuando el formulario manda
     * explícitamente bench_disponible=false; quien no lo manda no se ve afectado.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->has('bench_disponible') || filter_var($this->input('bench_disponible'), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            if (trim((string) $this->input('bench_text', '')) === '') {
                $validator->errors()->add('bench_text', 'Si el bench no está disponible, la descripción del bench es obligatoria.');
            }

            $existente = $this->route('project')?->evaluation?->bench_image;
            $quita = $this->boolean('remove_bench_image');
            if (!$this->hasFile('bench_image') && (!$existente || $quita)) {
                $validator->errors()->add('bench_image', 'Si el bench no está disponible, la imagen del bench es obligatoria.');
            }
        });
    }
}

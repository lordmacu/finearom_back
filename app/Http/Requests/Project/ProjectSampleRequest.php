<?php

namespace App\Http\Requests\Project;

use App\Support\ProjectOwnership;
use Illuminate\Foundation\Http\FormRequest;

class ProjectSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Regla de dueño: una comercial solo edita proyectos donde es la ejecutiva
        return ProjectOwnership::canManage($this->user(), $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'cantidad'         => ['nullable', 'numeric', 'min:0'],
            'cantidad_copias'  => ['nullable', 'integer', 'min:0'],
            'observaciones'    => ['nullable', 'string', 'max:2000'],
        ];
    }
}

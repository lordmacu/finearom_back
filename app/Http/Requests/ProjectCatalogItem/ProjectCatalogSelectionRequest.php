<?php

namespace App\Http\Requests\ProjectCatalogItem;

use App\Support\ProjectOwnership;
use Illuminate\Foundation\Http\FormRequest;

class ProjectCatalogSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Misma regla de dueño que editar el proyecto
        return ProjectOwnership::canManage($this->user(), $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'item_ids'   => ['present', 'array'],
            'item_ids.*' => ['integer'],
        ];
    }
}

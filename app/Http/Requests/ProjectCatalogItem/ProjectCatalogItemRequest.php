<?php

namespace App\Http\Requests\ProjectCatalogItem;

use App\Models\ProjectCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esNuevo = $this->isMethod('post') && !$this->route('item');
        // La carpeta debe ser del mismo tipo que el ítem
        $tipo = $esNuevo ? $this->input('tipo') : $this->route('item')?->tipo;

        return [
            // El tipo solo se define al crear
            'tipo'     => [$esNuevo ? 'required' : 'prohibited', 'in:' . implode(',', array_keys(ProjectCatalogItem::TIPOS))],
            'folder_id' => ['nullable', 'integer', Rule::exists('project_catalog_folders', 'id')->where('tipo', $tipo)],
            'name'     => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'photo'    => ['nullable', 'image', 'max:5120'],
            'active'   => ['nullable', 'boolean'],
        ];
    }
}

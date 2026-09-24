<?php

namespace App\Http\Requests\ProjectCatalogItem;

use App\Models\ProjectCatalogItem;
use Illuminate\Foundation\Http\FormRequest;

class ProjectCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // El tipo solo se define al crear
            'tipo'     => [$this->isMethod('post') && !$this->route('item') ? 'required' : 'prohibited', 'in:' . implode(',', array_keys(ProjectCatalogItem::TIPOS))],
            'name'     => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'photo'    => ['nullable', 'image', 'max:5120'],
            'active'   => ['nullable', 'boolean'],
        ];
    }
}

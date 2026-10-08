<?php

namespace App\Http\Requests\ProjectCatalogItem;

use App\Models\ProjectCatalogFolder;
use App\Models\ProjectCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectCatalogFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esNueva = !$this->route('folder');
        $tipo    = $esNueva ? $this->input('tipo') : $this->route('folder')->tipo;

        return [
            // El tipo solo se define al crear
            'tipo'      => [$esNueva ? 'required' : 'prohibited', 'in:' . implode(',', array_keys(ProjectCatalogItem::TIPOS))],
            'name'      => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('project_catalog_folders', 'id')->where('tipo', $tipo)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $folder   = $this->route('folder');
            $parentId = $this->input('parent_id');

            // Mover una carpeta dentro de sí misma o de una descendiente crearía un ciclo
            if ($folder instanceof ProjectCatalogFolder && $parentId && $folder->incluye((int) $parentId)) {
                $validator->errors()->add('parent_id', 'No se puede mover una carpeta dentro de sí misma o de sus subcarpetas.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Proforma;

use Illuminate\Foundation\Http\FormRequest;

class ProformaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es requerido',
            'file.file' => 'Debe ser un archivo válido',
            'file.mimes' => 'El archivo debe ser de tipo Excel (.xlsx o .xls)',
            'file.max' => 'El archivo no debe superar 10MB',
        ];
    }
}

<?php

namespace App\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

class CarteraImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array'],
            'files.*' => ['required', 'file', 'mimes:xls,xlsx'],
            'dias_mora' => ['required', 'integer'],
            'dias_cobro' => ['required', 'integer'],
        ];
    }
}


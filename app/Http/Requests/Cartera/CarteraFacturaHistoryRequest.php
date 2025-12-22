<?php

namespace App\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

class CarteraFacturaHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documento' => ['required', 'string', 'max:255'],
        ];
    }
}


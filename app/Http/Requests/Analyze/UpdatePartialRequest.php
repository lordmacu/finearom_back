<?php

namespace App\Http\Requests\Analyze;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePartialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dispatch_date' => ['nullable', 'date'],
            'quantity' => ['required', 'integer', 'min:1'],
            'trm' => ['required', 'numeric', 'min:0'],
        ];
    }
}


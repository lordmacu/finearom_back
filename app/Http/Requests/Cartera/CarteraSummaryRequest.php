<?php

namespace App\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

class CarteraSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_monthly' => ['nullable', 'in:true,false,1,0'],
            'week_number' => ['nullable', 'integer', 'min:1', 'max:4'],
            'executive_email' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'catera_type' => ['nullable', 'in:nacional,internacional'],
        ];
    }
}


<?php

namespace App\Http\Requests\BranchOffice;

use Illuminate\Foundation\Http\FormRequest;

class BranchOfficeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:255'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'delivery_city' => ['required', 'string', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'general_observations' => ['nullable', 'string', 'max:1000'],
            'main_function' => ['nullable', 'string', 'max:500'],
        ];
    }
}


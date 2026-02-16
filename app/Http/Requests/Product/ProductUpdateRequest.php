<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class ProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'discounts' => ['sometimes', 'array'],
            'discounts.*.min_quantity' => ['required', 'numeric', 'min:0'],
            'discounts.*.discount_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}


<?php

namespace App\Http\Requests\Product;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validSlugs = ProductCategory::active()->pluck('slug')->toArray();

        return [
            'code' => ['required', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in($validSlugs)],
            'discounts' => ['sometimes', 'array'],
            'discounts.*.min_quantity' => ['required', 'numeric', 'min:0'],
            'discounts.*.discount_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}


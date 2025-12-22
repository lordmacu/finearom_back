<?php

namespace App\Http\Requests\Analyze;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'creation_date' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            // Query params usually arrive as strings (e.g. "false"), so accept those too.
            'paginate' => ['nullable', 'in:true,false,1,0'],
        ];
    }
}

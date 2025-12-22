<?php

namespace App\Http\Requests\Analyze;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeClientPartialsRequest extends FormRequest
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
        ];
    }
}


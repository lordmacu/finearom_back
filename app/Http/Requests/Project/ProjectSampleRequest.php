<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cantidad'      => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

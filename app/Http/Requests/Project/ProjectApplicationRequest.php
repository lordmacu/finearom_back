<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dosis'         => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

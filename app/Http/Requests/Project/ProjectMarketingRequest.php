<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMarketingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'marketing'     => ['nullable', 'array'],
            'marketing.*'   => ['nullable', 'string', 'max:255'],
            'calidad'       => ['nullable', 'array'],
            'calidad.*'     => ['nullable', 'string', 'max:255'],
            'obs_marketing' => ['nullable', 'string', 'max:2000'],
            'obs_calidad'   => ['nullable', 'string', 'max:2000'],
        ];
    }
}

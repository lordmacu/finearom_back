<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectDeliverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department' => 'required|in:desarrollo,laboratorio,mercadeo,calidad,especiales',
        ];
    }
}

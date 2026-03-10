<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectExternalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'        => 'required|in:Ganado,Perdido',
            'razon_perdida' => 'nullable|string|max:500',
        ];
    }
}

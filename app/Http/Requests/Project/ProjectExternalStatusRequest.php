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
            // 'Cancelado' es el estado inicial (ProjectController::store y duplicate)
            // y el menú de ProjectShow deja volver a él.
            'status'        => 'required|in:Cancelado,Ganado,Perdido',
            'razon_perdida' => 'nullable|string|max:500',
        ];
    }
}

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
            // 'Sin definir' es el estado inicial (store, duplicate y reabrir): no se
            // elige a mano; el resultado se marca explícitamente.
            'status'        => 'required|in:Cancelado,Ganado,Perdido',
            'razon_perdida' => 'nullable|string|max:500',
        ];
    }
}

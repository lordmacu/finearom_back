<?php

namespace App\Http\Requests\Project;

use App\Models\User;
use App\Support\ProjectEngineerPermission;
use Illuminate\Foundation\Http\FormRequest;

class ProjectAssignEngineerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desarrollador_id' => [
                'required',
                'integer',
                function (string $attribute, $value, $fail) {
                    $user = User::find($value);
                    if (!$user || !$user->hasRole(ProjectEngineerPermission::ROLE)) {
                        $fail('El usuario elegido no es un ingeniero de desarrollo.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'desarrollador_id.required' => 'Selecciona un ingeniero.',
        ];
    }
}

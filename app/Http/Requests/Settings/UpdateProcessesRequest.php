<?php

namespace App\Http\Requests\Settings;

use App\Models\Process;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProcessesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.email' => ['required', 'email', 'max:255'],
            'rows.*.process_type' => ['required', 'string', Rule::in(Process::TYPES)],
        ];
    }
}


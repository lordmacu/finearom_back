<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'backup' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+\\.sql$/'],
        ];
    }
}


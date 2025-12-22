<?php

namespace App\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

class CarteraEstadoQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'type_queue' => ['required', 'in:balance_notification,order_block'],
            'data' => ['required', 'array', 'min:1'],
            'data.*.nit' => ['required', 'string', 'max:20'],
            'data.*.dispatch_confirmation_email' => ['nullable', 'array'],
            'data.*.dispatch_confirmation_email.*' => ['nullable', 'string', 'max:255'],
            'data.*.emails' => ['nullable', 'array'],
            'data.*.emails.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}


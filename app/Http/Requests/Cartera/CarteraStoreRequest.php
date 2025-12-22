<?php

namespace App\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

class CarteraStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'fechaFrom' => ['required', 'date'],
            'fechaTo' => ['required', 'date'],
            'cartera' => ['required', 'array', 'min:1'],

            'cartera.*.nit' => ['required', 'string', 'max:20'],
            'cartera.*.ciudad' => ['required', 'string', 'max:100'],
            'cartera.*.cuenta' => ['required', 'string', 'max:50'],
            'cartera.*.descripcion_cuenta' => ['required', 'string', 'max:255'],
            'cartera.*.documento' => ['required', 'string', 'max:255'],
            'cartera.*.fecha' => ['required', 'date'],
            'cartera.*.vence' => ['required', 'date'],
            'cartera.*.dias' => ['required'],
            'cartera.*.saldo_contable' => ['required'],
            'cartera.*.vencido' => ['required'],
            'cartera.*.saldo_vencido' => ['required'],
            'cartera.*.nombre_empresa' => ['required', 'string', 'max:255'],
            'cartera.*.catera_type' => ['required', 'string', 'max:255'],

            // optional fields that may exist depending on the excel header
            'cartera.*.vendedor' => ['nullable', 'string', 'max:255'],
            'cartera.*.nombre_vendedor' => ['nullable', 'string', 'max:255'],
        ];
    }
}


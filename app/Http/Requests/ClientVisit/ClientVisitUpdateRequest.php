<?php

namespace App\Http\Requests\ClientVisit;

use Illuminate\Foundation\Http\FormRequest;

class ClientVisitUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo'            => 'sometimes|string|max:300',
            'fecha_inicio'      => 'sometimes|date_format:Y-m-d H:i:s',
            'client_id'         => 'sometimes|nullable|integer|exists:clients,id',
            'prospect_id'       => 'sometimes|nullable|integer|exists:prospects,id',
            'nombre_cliente'    => 'sometimes|nullable|string|max:300',
            'user_id'           => 'sometimes|nullable|integer|exists:users,id',
            'fecha_fin'         => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'lugar'             => 'sometimes|nullable|string|max:300',
            'tipo'              => 'sometimes|in:presencial,llamada,videollamada',
            'notas'             => 'sometimes|nullable|string',
            'google_event_id'   => 'sometimes|nullable|string|max:200',
            'google_event_link' => 'sometimes|nullable|string|max:500',
            'estado'            => 'sometimes|in:programada,realizada,cancelada',
        ];
    }
}

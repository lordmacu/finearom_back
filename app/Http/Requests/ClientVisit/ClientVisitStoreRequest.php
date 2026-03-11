<?php

namespace App\Http\Requests\ClientVisit;

use Illuminate\Foundation\Http\FormRequest;

class ClientVisitStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo'             => 'required|string|max:300',
            'fecha_inicio'       => 'required|date_format:Y-m-d H:i:s',
            'client_id'          => 'nullable|integer|exists:clients,id',
            'prospect_id'        => 'nullable|integer|exists:prospects,id',
            'nombre_cliente'     => 'nullable|string|max:300',
            'user_id'            => 'nullable|integer|exists:users,id',
            'fecha_fin'          => 'nullable|date_format:Y-m-d H:i:s|after_or_equal:fecha_inicio',
            'lugar'              => 'nullable|string|max:300',
            'tipo'               => 'nullable|in:presencial,llamada,videollamada',
            'notas'              => 'nullable|string',
            'google_event_id'    => 'nullable|string|max:200',
            'google_event_link'  => 'nullable|string|max:500',
            'estado'             => 'nullable|in:programada,realizada,cancelada',

            'create_calendar_event' => 'nullable|boolean',

            'commitments'                        => 'nullable|array',
            'commitments.*.descripcion'          => 'required|string',
            'commitments.*.responsable'          => 'nullable|string|max:200',
            'commitments.*.fecha_estimada'       => 'nullable|date_format:Y-m-d',
            'commitments.*.completado'           => 'nullable|boolean',
        ];
    }
}

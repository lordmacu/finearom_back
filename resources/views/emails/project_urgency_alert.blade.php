@extends('emails.layout')

@section('email_title', '⚠️ Proyecto Urgente')

@section('content')
<p>Hola,</p>
<p>
    El siguiente proyecto tiene su fecha requerida en <strong>2 días hábiles o menos</strong> y aún está en proceso:
</p>

<table>
    <thead>
        <tr>
            <th>Campo</th>
            <th>Detalle</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Proyecto</strong></td>
            <td>{{ $project->nombre }}</td>
        </tr>
        <tr>
            <td><strong>Cliente</strong></td>
            <td>{{ $project->client?->client_name ?? $project->nombre_prospecto ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Tipo</strong></td>
            <td>{{ $project->tipo }}</td>
        </tr>
        <tr>
            <td><strong>Fecha requerida</strong></td>
            <td>{{ $project->fecha_requerida?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Estado interno</strong></td>
            <td>{{ $project->estado_interno ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Ejecutivo</strong></td>
            <td>{{ $project->ejecutivo ?? '—' }}</td>
        </tr>
    </tbody>
</table>

<p>
    <a href="{{ config('app.frontend_url', 'https://ordenes.finearom.co') }}/projects/{{ $project->id }}">
        Ver proyecto en el sistema →
    </a>
</p>
@endsection

@section('signature')
<p>Este mensaje fue generado automáticamente por el sistema Finearom.</p>
@endsection

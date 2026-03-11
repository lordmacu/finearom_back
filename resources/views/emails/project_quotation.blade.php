@extends('emails.layout')

@section('email_title', 'Cotización')

@section('content')
<p>Estimado(a) {{ $project->client?->client_name ?? $project->nombre_prospecto ?? 'cliente' }},</p>

<p>Esperamos que se encuentren muy bien.</p>

<p>
    Adjunto encontrará la cotización del proyecto <strong>{{ $project->nombre }}</strong>
    (versión {{ $version }}), preparada por nuestro equipo.
</p>

<table>
    <tbody>
        <tr>
            <td><strong>Proyecto</strong></td>
            <td>{{ $project->nombre }}</td>
        </tr>
        <tr>
            <td><strong>Tipo</strong></td>
            <td>{{ $project->tipo }}</td>
        </tr>
        @if($project->tipo_producto)
        <tr>
            <td><strong>Tipo de producto</strong></td>
            <td>{{ $project->tipo_producto }}</td>
        </tr>
        @endif
        @if($project->ejecutivo)
        <tr>
            <td><strong>Ejecutivo</strong></td>
            <td>{{ $project->ejecutivo }}</td>
        </tr>
        @endif
        <tr>
            <td><strong>Versión</strong></td>
            <td>{{ $version }}</td>
        </tr>
    </tbody>
</table>

<p>
    Quedamos atentos a cualquier consulta o ajuste que requieran.
</p>

<p>Que tengan un excelente día.</p>
@endsection

@section('signature')
<p><strong>Equipo Finearom</strong></p>
@endsection

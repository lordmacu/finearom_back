@extends('emails.layout')

@section('title', 'Completa tu información - Finearom')
@section('email_title', 'Actualización de Información')

@section('content')
    <p>Hola {{ $client->client_name ?? 'Cliente' }},</p>
    <p>Te enviamos el enlace para completar o actualizar tu información.</p>
    <p>
        <a href="{{ $link }}">
            Completar información
        </a>
    </p>
    <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
    <p>{{ $link }}</p>
    <p>Gracias.</p>
@endsection

@section('signature')
    <div>
        <p><strong>EQUIPO FINEAROM</strong></p>
    </div>
@endsection

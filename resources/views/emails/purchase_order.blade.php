@extends('emails.layout')

@section('title', 'Orden de Compra - Finearom')
@section('email_title', 'Orden de Compra')

@section('content')
    <div>
        {!! $templateContent !!}
    </div>
@endsection

@section('signature')
    <div>
        <p><strong>EQUIPO FINEAROM</strong></p>
        <p>Atención al Cliente</p>
        <p>📱 +57 317 433 5096 | ✉️ <a href="mailto:info@finearom.com">info@finearom.com</a> | 🌐 <a href="https://finearom.com">www.finearom.com</a></p>
    </div>
@endsection

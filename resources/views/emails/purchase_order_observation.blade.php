@extends('emails.layout')

@section('title', 'Observaciones Orden de Compra - Finearom')
@section('email_title', 'Observaciones de Orden de Compra')

@section('content')
    <p><strong>Orden:</strong> {{ $order->order_consecutive }}</p>
    <p><strong>Cliente:</strong> {{ $order->client->client_name }} ({{ $order->client->nit }})</p>

    @isset($observationHtml)
        <div>
            <h3>Observaciones</h3>
            <div>{!! $observationHtml !!}</div>
        </div>
    @endisset

    @if (!empty($internalHtml) && empty($forClient))
        <div style="margin-top: 20px;">
            <h3>Observaciones internas (solo planta)</h3>
            <div>{!! $internalHtml !!}</div>
        </div>
    @endif
@endsection

@section('signature')
    <div>
        <p><strong>Equipo Operaciones FINEAROM</strong></p>
        <p>Gestión de Órdenes</p>
        <p>Tel: +57 317 433 5096 | <a href="mailto:servicio.cliente@finearom.com">servicio.cliente@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
    </div>
@endsection

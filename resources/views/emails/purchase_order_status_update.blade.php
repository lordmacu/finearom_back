@extends('emails.layout')

@section('title', 'Actualización de Orden - Finearom')
@section('email_title', 'Actualización de Orden')

@section('content')
    <p>Estimado cliente,</p>
    <p>Nos dirigimos a usted para mantenerlo informado sobre el estado actual de su orden de compra.</p>

    @if (!empty($statusCommentHtml))
        <div>
            <p><strong>Observaciones importantes</strong></p>
            <div>{!! $statusCommentHtml !!}</div>
        </div>
    @endif

    <div>
        <p><strong>Agradecemos su atención y confianza</strong></p>
    </div>

    <p>Si tiene alguna pregunta o requiere información adicional, no dude en contactarnos. Estamos aquí para brindarle el mejor servicio.</p>
    <p>Cordialmente,</p>
@endsection

@section('signature')
    <div>
        <p><strong>{{ $purchaseOrder->sender_name ?? 'EQUIPO FINEAROM' }}</strong></p>
        <p>Gestión de Órdenes</p>
        <p>Tel: +57 317 433 5096 | <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
    </div>
@endsection

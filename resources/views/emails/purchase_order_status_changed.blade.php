@extends('emails.layout')

@section('title', 'Actualización de Estado - Finearom')
@section('email_title', 'Actualización de Orden')

@section('content')
    <p><strong>Orden:</strong> {{ $order->order_consecutive }}</p>
    <p><strong>Cliente:</strong> {{ $order->client->client_name }} ({{ $order->client->nit }})</p>

    <div style="margin: 20px 0;">
        <h3>Estado actualizado</h3>
        <p><strong>Nuevo estado:</strong>
            @php
                $statusTranslations = [
                    'pending' => 'Pendiente',
                    'processing' => 'En Proceso',
                    'completed' => 'Completada',
                    'cancelled' => 'Cancelada',
                    'parcial_status' => 'Parcial',
                ];
                echo $statusTranslations[$order->status] ?? 'Estado Desconocido';
            @endphp
        </p>
    </div>

    <p>Estimado cliente,</p>
    <p>Nos complace informarle que el estado de su orden de compra ha sido actualizado en nuestro sistema.</p>

    <div style="margin: 20px 0;">
        <h3>Detalles de la Orden</h3>
        <p><strong>Fecha de Creación:</strong> {{ $order->order_creation_date }}</p>
        <p><strong>Fecha de Entrega Requerida:</strong> {{ $order->required_delivery_date }}</p>
        <p><strong>Dirección de Entrega:</strong> {{ $order->delivery_address }}</p>
    </div>

    <p>¡Gracias por confiar en Finearom!</p>
    <p>Continuamos trabajando para brindarle el mejor servicio y mantenerlo informado sobre el progreso de su orden.</p>
@endsection

@section('signature')
    <div>
        <p><strong>EQUIPO FINEAROM</strong></p>
        <p>Gestión de Órdenes</p>
        <p>Tel: +57 317 433 5096 | <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
    </div>
@endsection

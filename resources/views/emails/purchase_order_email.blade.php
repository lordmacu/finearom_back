@extends('emails.layout')

@php
    // Comentario principal (legacy: type = order_comment)
    $orderComment = $purchaseOrder->comments->where('type', 'order_comment')->first() ?? null;
@endphp

@section('title', 'Procesamiento de Orden de Compra - Finearom')
@section('email_title', 'Procesamiento de Orden')

@section('content')
    <p><strong>Buen día a todos,</strong></p>
    <p>Espero que se encuentren muy bien.</p>
    <p>Quisiera solicitar su ayuda con el procesamiento de la siguiente orden de compra:</p>

    <div>
        <p><strong>📅 Fecha requerida de entrega:</strong></p>
        <p>{{ $purchaseOrder->required_delivery_date }}</p>
    </div>

    @if ($orderComment)
        <div>
            <p><strong>📝 Observaciones importantes:</strong></p>
            <div>{!! $orderComment->text !!}</div>
        </div>
    @endif

    <div>
        <h3>📋 Detalle de Productos</h3>

        <table>
            <thead>
                <tr>
                    <th>REFERENCIA</th>
                    <th>CÓDIGO</th>
                    <th>CANTIDAD</th>
                    <th>PRECIO U</th>
                    <th>PRECIO TOTAL</th>
                    <th>NEW WIN</th>
                    <th>LUGAR DE ENTREGA</th>
                    <th>FECHA DE DESPACHO</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($purchaseOrder->products as $product)
                    @php
                        $effectivePrice = ($product->pivot->price > 0) ? $product->pivot->price : $product->price;
                    @endphp
                    <tr>
                        <td><strong>{{ $product->product_name }}</strong></td>
                        <td>
                            {{ strpos($product->code, $purchaseOrder->client->nit) === 0
                                ? substr($product->code, strlen($purchaseOrder->client->nit))
                                : $product->code }}
                        </td>
                        <td>{{ $product->pivot->quantity }}</td>
                        <td>${{ number_format($effectivePrice, 2) }}</td>
                        <td>
                            ${{ number_format($product->pivot->muestra == '1' ? 0 : $effectivePrice * $product->pivot->quantity, 2) }}
                        </td>
                        <td>
                            @if ($product->pivot->new_win == 1)
                                Sí
                            @else
                                No
                            @endif
                        </td>
                        <td> {{ $purchaseOrder->getBranchOfficeName($product) }}</td>
                        <td> {{ $product->pivot->delivery_date }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="5"></td>
                    <td><strong> TOTAL</strong></td>
                    <td colspan="2">
                        <strong>${{ number_format(
                            $purchaseOrder->products->sum(function ($product) {
                                $effectivePrice = ($product->pivot->price > 0) ? $product->pivot->price : $product->price;
                                return $product->pivot->muestra == '1' ? 0 : $effectivePrice * $product->pivot->quantity;
                            }),
                            2,
                        ) }}
                            USD</strong>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div>
        <h4>💱 Información TRM</h4>
        <div>
            @if ($purchaseOrder->trm_updated_at)
                TRM de cliente: ${{ $purchaseOrder->trm }}
            @else
                TRM del día {{ optional($purchaseOrder->created_at)->format('d/m/Y') }}:
                ${{ $purchaseOrder->trm }}
            @endif
        </div>
    </div>

    <p><strong>Agradezco su atención y colaboración.</strong></p>
    <p>Quedo muy atento a sus comentarios y confirmen por favor la disponibilidad para cumplir con la fecha requerida.</p>
@endsection

@section('signature')
    <div>
        <p><strong>EQUIPO COMERCIAL FINEAROM</strong></p>
        <p>Gestión de Órdenes</p>
        <p>📱 +57 317 433 5096 | ✉️ <a href="mailto:comercial@finearom.com">comercial@finearom.com</a> | 🌐 <a href="https://finearom.com">www.finearom.com</a></p>
    </div>
@endsection

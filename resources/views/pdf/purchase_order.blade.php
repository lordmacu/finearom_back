<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden de compra</title>
    <style>
        /* Configuración específica para PDF */
        @page {
            size: A4;
            margin: 15mm;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.3;
            color: #333;
        }

        .container {
            width: 100%;
            max-width: 100%;
            background-color: white;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 15px;
        }

        .company-info {
            display: table-cell;
            width: 60%;
            vertical-align: top;
        }

        .invoice-info {
            display: table-cell;
            width: 40%;
            vertical-align: top;
            text-align: right;
        }

        .logo-section {
            margin-bottom: 10px;
        }

        .company-name {
            display: inline-block;
            font-size: 20px;
            color: #333;
            margin: 0;
            vertical-align: middle;
            font-weight: bold;
        }

        .company-details {
            font-size: 11px;
            color: #666;
            line-height: 1.4;
            margin-top: 8px;
        }

        .company-details p {
            margin: 2px 0;
        }

        .invoice-title {
            font-size: 16px;
            color: #2c5aa0;
            font-weight: bold;
            margin: 0 0 5px 0;
        }

        .invoice-number {
            font-size: 12px;
            color: #666;
            margin: 0;
        }

        .order-details-section {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2c5aa0;
        }

        .order-details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .order-details-table td {
            padding: 5px 0;
            font-size: 11px;
        }

        .order-details-table td:first-child {
            font-weight: bold;
            color: #2c5aa0;
            width: 40%;
        }

        .order-details-table td:last-child {
            text-align: right;
            color: #333;
        }

        .client-info {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .client-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 10px;
        }

        .contact-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-left: 10px;
        }

        .info-box {
            border: 1px solid #e1e1e1;
            padding: 15px;
            border-radius: 8px;
            background-color: #fafafa;
            height: 100px;
        }

        .info-box h3 {
            font-size: 14px;
            margin: 0 0 8px 0;
            color: #2c5aa0;
            font-weight: bold;
        }

        .info-box p {
            margin: 3px 0;
            font-size: 11px;
            color: #504d5e;
            line-height: 1.4;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }

        .products-table th {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            padding: 8px 6px;
            text-align: left;
            font-weight: bold;
            color: #686868;
            font-size: 11px;
        }

        .products-table td {
            border: 1px solid #ddd;
            padding: 8px 6px;
            vertical-align: top;
            font-size: 11px;
        }

        .products-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .totals-section {
            display: table;
            width: 100%;
            margin-top: 20px;
        }

        .empty-space {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .totals-container {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-left: auto;
            max-width: 250px;
        }

        .totals-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
        }

        .totals-table td:first-child {
            text-align: left;
            font-weight: bold;
            color: #686868;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: bold;
            color: #686868;
            font-size: 12px;
        }

        .total-row {
            background-color: #f0f8ff;
            border-top: 2px solid #2c5aa0;
        }

        .total-amount {
            font-weight: bold;
            font-size: 14px;
            color: #2c5aa0;
        }

        .observations {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-left: 4px solid #2c5aa0;
            border-radius: 4px;
        }

        .observations h4 {
            margin: 0 0 10px 0;
            color: #2c5aa0;
            font-size: 13px;
            font-weight: bold;
        }

        .observations div {
            font-size: 11px;
            color: #333;
            line-height: 1.4;
        }

        /* Estilos específicos para alineación */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-left {
            text-align: left;
        }

        .font-bold {
            font-weight: bold;
        }

        /* Evitar saltos de página en elementos críticos */
        .header,
        .client-info,
        .totals-table {
            page-break-inside: avoid;
        }

        /* Optimización para impresión */
        @media print {
            .container {
                margin: 0;
                box-shadow: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <div class="logo-section">
                    <img style="height: 35px; vertical-align: middle; margin-right: 10px;" src="{{ $logo }}"
                        alt="Logo">
                </div>
                <div class="company-details">
                    <p><strong>NIT:</strong> 900.220.672-8</p>
                    <p>CRA 50 134 D 31</p>
                    <p>BOGOTÁ - COLOMBIA</p>
                    <p>57 1 6150035</p>
                    <p>servicio.cliente@finearom.com</p>
                </div>
            </div>
            <div class="invoice-info">
                <h2 class="invoice-title">ORDEN DE COMPRA</h2>
                <p class="invoice-number"><strong>{{ $purchaseOrder->order_consecutive }}</strong></p>
            </div>
        </div>

        <!-- Order Details Section -->
        <div class="order-details-section">
            <table class="order-details-table">
                <tr>
                    <td>Consecutivo:</td>
                    <td>{{ $purchaseOrder->order_consecutive }}</td>
                </tr>
            </table>
        </div>

        <!-- Client and Contact Info -->
        <div class="client-info">
            <div class="client-details">
                <div class="info-box">
                    <h3>Cliente</h3>
                    <p><strong>{{ $purchaseOrder->client->client_name }}</strong></p>
                    <p>{{ $purchaseOrder->contact }}</p>
                </div>
            </div>
            <div class="contact-details">
                <div class="info-box">
                    <h3>Información de Contacto</h3>
                    <p><strong>Teléfono:</strong> {{ $purchaseOrder->phone }}</p>
                </div>
            </div>
        </div>

        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th width="30%">PRODUCTO</th>
                    <th width="15%">PRECIO</th>
                    <th width="10%">CANTIDAD</th>
                    <th width="15%">TOTAL</th>
                    <th width="30%">SUCURSAL</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $subtotal = 0;
                @endphp
                @foreach ($purchaseOrder->products as $product)
                    @php
                        $effectivePrice = ($product->pivot->price > 0) ? $product->pivot->price : $product->price;
                    @endphp
                    <tr>
                        <td>{{ $product->product_name }}</td>
                        <td class="text-right">{{ number_format($effectivePrice, 2) }}</td>
                        <td class="text-center">{{ $product->pivot->quantity }}</td>
                        <td class="text-right font-bold">
                            {{ number_format($effectivePrice * $product->pivot->quantity, 2) }}</td>
                        <td>{{ $purchaseOrder->getBranchOfficeName($product) }}</td>
                    </tr>
                    @php
                        $subtotal += $effectivePrice * $product->pivot->quantity;
                    @endphp
                @endforeach
            </tbody>
        </table>

        @php
            $ivaRate = 0.19; // Tasa de IVA del 19%
            $reteicaRate = 0.01; // Tasa de reteICA del 1%
            $iva = $subtotal * $ivaRate;
            $reteica = $subtotal * $reteicaRate;
            $total = $subtotal; //+ $iva - $reteica;
        @endphp

        <!-- Footer with totals -->
        <div class="totals-section">
            <div class="empty-space">
                <p><strong>Total Productos:</strong> {{ count($purchaseOrder->products) }}</p>
            </div>

            <div class="totals-container">
                <table class="totals-table">
                    <tr>
                        <td>SUBTOTAL</td>
                        <td>{{ number_format($subtotal, 2) }} USD</td>
                    </tr>
                    <tr class="total-row">
                        <td class="total-amount">TOTAL</td>
                        <td class="total-amount">{{ number_format($total, 2) }} USD</td>
                    </tr>
                </table>
            </div>
        </div>

        @php
            $orderComment = $purchaseOrder->comments->where('type', 'order_comment')->first();
        @endphp

        @if ($orderComment)
            <div class="observations">
                <h4>Observaciones</h4>
                <div>{!! $orderComment->text !!}</div>
            </div>
        @endif

    </div>
</body>

</html>

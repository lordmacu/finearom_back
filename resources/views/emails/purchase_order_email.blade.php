@php
    $orderComment = $purchaseOrder->comments->where('type', 'order_comment')->first();
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesamiento de Orden de Compra</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }

        .email-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .logo {
            margin-right: 20px;
        }

        .logo img {
            max-width: 150px;
            height: auto;
        }

        .header-text {
            flex: 1;
        }

        .header-text h1 {
            color: #2c5aa0;
            font-size: 24px;
            margin: 0;
            font-weight: bold;
        }

        .header-text .subtitle {
            color: #666;
            font-size: 14px;
            margin: 5px 0 0 0;
        }

        .greeting {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
        }

        .content {
            margin-bottom: 30px;
        }

        .order-summary {
            background: linear-gradient(135deg, #2c5aa0, #1e3f73);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }

        .order-summary h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
        }

        .summary-label {
            font-weight: bold;
        }

        .required-date {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }

        .required-date .date {
            font-size: 24px;
            font-weight: bold;
            color: #856404;
            margin-top: 10px;
        }

        .observations {
            background: #e8f4fd;
            border: 1px solid #2c5aa0;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #2c5aa0;
        }

        .observations h4 {
            color: #2c5aa0;
            margin: 0 0 10px 0;
            font-size: 16px;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .products-table th {
            background: #2c5aa0;
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: bold;
            font-size: 14px;
        }

        .products-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .products-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .products-table tr:hover {
            background: #e8f4fd;
        }

        .total-row {
            background: #2c5aa0 !important;
            color: white;
            font-weight: bold;
        }

        .total-row td {
            border-bottom: none;
            padding: 15px 12px;
        }

        .price-cell {
            text-align: right;
            font-weight: 500;
        }

        .quantity-cell {
            text-align: center;
            font-weight: bold;
        }

        .trm-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #28a745;
        }

        .trm-info h4 {
            color: #28a745;
            margin: 0 0 10px 0;
            font-size: 16px;
        }

        .trm-value {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }

        .signature {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .signature-name {
            font-weight: bold;
            font-size: 16px;
            color: #2c5aa0;
            margin-bottom: 5px;
        }

        .signature-title {
            font-weight: 500;
            color: #666;
            margin-bottom: 10px;
        }

        .contact-info {
            font-size: 14px;
            color: #666;
        }

        .contact-info a {
            color: #2c5aa0;
            text-decoration: none;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            text-align: center;
            font-size: 12px;
            color: #999;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .email-container {
                padding: 20px;
            }

            .header {
                flex-direction: column;
                text-align: center;
            }

            .logo {
                margin-right: 0;
                margin-bottom: 15px;
            }

            .logo img {
                max-width: 60px;
            }

            .products-table {
                font-size: 12px;
            }

            .products-table th,
            .products-table td {
                padding: 8px 6px;
            }

            .summary-item {
                flex-direction: column;
            }

            .required-date .date {
                font-size: 20px;
            }
        }

        @media (max-width: 600px) {
            .products-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>

<body>
    <div class="email-container">
        <div class="header">
            <div class="logo">
                <img width="150px" src="https://ordenes.finearom.co/images/logo.png" alt="Finearom Logo">
            </div>
            <div class="header-text">
                <h1>Procesamiento de Orden</h1>
                <div class="subtitle">Solicitud de Procesamiento Interno</div>
            </div>
        </div>

        <div class="greeting">
            <p> <strong>Buen día a todos,</strong></p>
            <p>Espero que se encuentren muy bien.</p>
            <p>Quisiera solicitar su ayuda con el procesamiento de la siguiente orden de compra:</p>
        </div>

        <div class="required-date">
            <div><strong>📅 Fecha requerida de entrega:</strong></div>
            <div class="date">{{ $purchaseOrder->required_delivery_date }}</div>
        </div>

        @if ($orderComment)
            <div class="observations">
                <h4>📝 Observaciones importantes:</h4>
                <div>{!! $orderComment->text !!}</div>
            </div>
        @endif

        <div class="content">
            <h3 style="color: #2c5aa0; margin-bottom: 15px;">📋 Detalle de Productos</h3>

            <table class="products-table">
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
                        <tr>
                            <td><strong>{{ $product->product_name }}</strong></td>
                            <td>
                                {{ strpos($product->code, $purchaseOrder->client->nit) === 0
                                    ? substr($product->code, strlen($purchaseOrder->client->nit))
                                    : $product->code }}
                            </td>
                            <td class="quantity-cell">{{ $product->pivot->quantity }}</td>
                            <td class="price-cell">${{ number_format($product->price, 2) }}</td>
                            <td class="price-cell">
                                ${{ number_format($product->pivot->muestra == '1' ? 0 : $product->price * $product->pivot->quantity, 2) }}
                            </td>
                            <td class="quantity-cell">
                                @if ($product->pivot->new_win == 1)
                                    <span style="color: #28a745; font-weight: bold;">✅ Sí</span>
                                @else
                                    <span style="color: #dc3545; font-weight: bold;">❌ No</span>
                                @endif
                            </td>
                            <td> {{ $purchaseOrder->getBranchOfficeName($product) }}</td>
                            <td> {{ $product->pivot->delivery_date }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="5"></td>
                        <td><strong> TOTAL</strong></td>
                        <td colspan="2" class="price-cell">
                            <strong>${{ number_format(
                                $purchaseOrder->products->sum(function ($product) {
                                    return $product->pivot->muestra == '1' ? 0 : $product->price * $product->pivot->quantity;
                                }),
                                2,
                            ) }}
                                USD</strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="trm-info">
            <h4>💱 Información TRM</h4>
            <div class="trm-value">
                @if ($purchaseOrder->trm_updated_at)
                    TRM de cliente: ${{ $purchaseOrder->trm }}
                @else
                    TRM del día {{ optional($purchaseOrder->created_at)->format('d/m/Y') }}:
                    ${{ $purchaseOrder->trm }}
                @endif
            </div>
        </div>

        <div class="content">
            <p><strong>Agradezco su atención y colaboración.</strong></p>
            <p>Quedo muy atento a sus comentarios y confirmen por favor la disponibilidad para cumplir con la fecha
                requerida.</p>
        </div>

        <div class="signature">
            <div class="signature-name">EQUIPO COMERCIAL FINEAROM</div>
            <div class="signature-title">Gestión de Órdenes</div>
            <div class="contact-info">
                📱 <a href="tel:+573174335096">+57 317 433 5096</a><br>
                ✉️ <a href="mailto:comercial@finearom.com">comercial@finearom.com</a><br>
                🌐 <a href="https://finearom.com">www.finearom.com</a>
            </div>
        </div>

        <div class="footer">
            <p>Este mensaje es para uso interno del equipo Finearom.</p>
            <p>&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</p>
        </div>
    </div>
</body>

</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->type->label() }} {{ $document->doc_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
            color: #1e1e2e;
            background: #ffffff;
            padding: 15mm 15mm 20mm 15mm;
        }

        /* ── Header layout ── */
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .logo-cell { width: 50%; vertical-align: top; }
        .doctype-cell { width: 50%; vertical-align: top; text-align: right; }

        .logo-box {
            display: inline-block;
            width: 48px;
            height: 48px;
            background-color: #4f46e5;
            border-radius: 10px;
            text-align: center;
            line-height: 48px;
            color: #ffffff;
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .company-name { font-size: 17px; font-weight: bold; color: #1e1e2e; }
        .company-meta { font-size: 10px; color: #6b7280; margin-top: 3px; line-height: 1.6; }

        .doc-type-label {
            font-size: 26px;
            font-weight: bold;
            color: #4f46e5;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }
        .doc-meta { font-size: 11px; color: #6b7280; margin-top: 6px; line-height: 1.8; }
        .doc-meta strong { color: #1e1e2e; }

        /* ── Divider ── */
        .divider { border: none; border-top: 2px solid #4f46e5; margin: 16px 0; }

        /* ── Bill To ── */
        .bill-to-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .bill-to-cell {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px 16px;
            background: #f9fafb;
            width: 45%;
            vertical-align: top;
        }
        .bill-to-label {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9ca3af;
            margin-bottom: 6px;
        }
        .bill-to-company { font-size: 13px; font-weight: bold; color: #1e1e2e; margin-bottom: 2px; }
        .bill-to-name { font-size: 11px; color: #374151; margin-bottom: 2px; }
        .bill-to-detail { font-size: 10px; color: #6b7280; line-height: 1.6; }

        /* ── Line items table ── */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .items-table thead tr { background-color: #4f46e5; }
        .items-table thead th {
            color: #ffffff;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 8px 10px;
            text-align: left;
            border: none;
        }
        .items-table thead th.right { text-align: right; }
        .items-table tbody td {
            padding: 8px 10px;
            font-size: 11px;
            color: #374151;
            border-bottom: 1px solid #f3f4f6;
        }
        .items-table tbody tr:nth-child(even) td { background-color: #f9fafb; }
        .items-table tbody td.right { text-align: right; font-family: monospace; }

        /* ── Totals ── */
        .totals-outer { width: 100%; border-collapse: collapse; margin-top: 0; }
        .totals-spacer { width: 55%; }
        .totals-block { width: 45%; vertical-align: top; }
        .totals-inner { width: 100%; border-collapse: collapse; }
        .totals-inner td { padding: 5px 10px; font-size: 11px; border: none; }
        .totals-inner td.label { color: #6b7280; }
        .totals-inner td.amount { text-align: right; font-family: monospace; color: #1e1e2e; }
        .totals-inner .discount-row td { color: #ef4444; }
        .totals-inner .total-row { border-top: 2px solid #4f46e5; }
        .totals-inner .total-row td { padding-top: 8px; font-size: 14px; font-weight: bold; color: #4f46e5; }

        /* ── Footer ── */
        .page-footer {
            position: fixed;
            bottom: 10mm;
            left: 15mm;
            right: 15mm;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
            font-size: 9px;
            color: #9ca3af;
            text-align: center;
        }

        /* ── Items wrapper card ── */
        .items-wrapper {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>

    @php
        $companyName    = \App\Models\Setting::get('company_name', config('app.name'));
        $companyAddress = \App\Models\Setting::get('company_address', '');
        $companyEmail   = \App\Models\Setting::get('company_email', '');
        $logoPath       = \App\Models\Setting::get('company_logo_path');
        $logoFullPath   = $logoPath ? storage_path('app/public/'.$logoPath) : null;
    @endphp

    {{-- ── Header ── --}}
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if($logoFullPath && file_exists($logoFullPath))
                    <img src="{{ $logoFullPath }}" style="max-height: 60px; margin-bottom: 8px; display: block;"><br>
                @else
                    <div class="logo-box">D</div><br>
                @endif
                <div class="company-name">{{ $companyName }}</div>
                <div class="company-meta">
                    @if($companyAddress){{ $companyAddress }}<br>@endif
                    @if($companyEmail){{ $companyEmail }}@endif
                </div>
            </td>
            <td class="doctype-cell">
                <div class="doc-type-label">{{ strtoupper($document->type->label()) }}</div>
                <div class="doc-meta">
                    <strong>Number:</strong> {{ $document->doc_number }}<br>
                    <strong>Date:</strong> {{ $document->doc_date->format('d M Y') }}<br>
                    @if($document->order_no)
                        <strong>Order Ref:</strong> {{ $document->order_no }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <hr class="divider">

    {{-- ── Bill To ── --}}
    <table class="bill-to-table">
        <tr>
            <td class="bill-to-cell">
                <div class="bill-to-label">Bill To</div>
                @if($document->customer->company_name)
                    <div class="bill-to-company">{{ $document->customer->company_name }}</div>
                @endif
                @if($document->customer->title || $document->customer->first_name || $document->customer->last_name)
                    <div class="bill-to-name">
                        {{ trim(($document->customer->title?->name ?? '').' '.($document->customer->first_name ?? '').' '.($document->customer->last_name ?? '')) }}
                    </div>
                @endif
                <div class="bill-to-detail">
                    @if($document->customer->address_1){{ $document->customer->address_1 }}<br>@endif
                    @if($document->customer->address_2){{ $document->customer->address_2 }}<br>@endif
                    @if($document->customer->town){{ $document->customer->town }}<br>@endif
                    @if($document->customer->post_code){{ $document->customer->post_code }}<br>@endif
                    @if($document->customer->email_1){{ $document->customer->email_1 }}@endif
                </div>
            </td>
            <td></td>
        </tr>
    </table>

    {{-- ── Line Items ── --}}
    @php $isDeliveryNote = $document->type->value === 'DN'; @endphp
    <div class="items-wrapper">
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:4%">#</th>
                    @if($isDeliveryNote)
                        <th style="width:56%">Description</th>
                        <th class="right" style="width:16%">Qty</th>
                        <th style="width:12%">Per</th>
                    @else
                        <th style="width:42%">Description</th>
                        <th class="right" style="width:10%">Qty</th>
                        <th class="right" style="width:14%">Price</th>
                        <th style="width:8%">Per</th>
                        <th class="right" style="width:14%">Line Value</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($document->items as $i => $item)
                    <tr>
                        <td style="color:#9ca3af">{{ $i + 1 }}</td>
                        <td>{{ $item->details }}</td>
                        <td class="right">{{ $item->quantity }}</td>
                        @if(! $isDeliveryNote)
                            <td class="right">£{{ number_format($item->price, 2) }}</td>
                        @endif
                        <td style="color:#9ca3af">{{ $item->per ?? '' }}</td>
                        @if(! $isDeliveryNote)
                            <td class="right" style="font-weight:600">£{{ number_format($item->line_value, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Totals (invoices only) ── --}}
    @if(! $isDeliveryNote)
        <table class="totals-outer">
            <tr>
                <td class="totals-spacer"></td>
                <td class="totals-block">
                    <table class="totals-inner">
                        <tr>
                            <td class="label">Subtotal</td>
                            <td class="amount">£{{ number_format($document->subtotal, 2) }}</td>
                        </tr>
                        @if($document->discount_amount > 0)
                            <tr class="discount-row">
                                <td class="label">Discount ({{ $document->trade_discount }}%)</td>
                                <td class="amount">-£{{ number_format($document->discount_amount, 2) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="label">VAT</td>
                            <td class="amount">£{{ number_format($document->vat_amount, 2) }}</td>
                        </tr>
                        <tr class="total-row">
                            <td class="label">Total</td>
                            <td class="amount">£{{ number_format($document->total_value, 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @endif

    {{-- ── Footer ── --}}
    <div class="page-footer">
        Thank you for your business.
        @if($document->customer->creditTerm)
            Payment terms: {{ $document->customer->creditTerm->name }}.
        @endif
        &mdash;
        {{ $companyName }}
        @if($companyAddress)&mdash; {{ $companyAddress }}@endif
        @if($companyEmail)&mdash; {{ $companyEmail }}@endif
    </div>

</body>
</html>

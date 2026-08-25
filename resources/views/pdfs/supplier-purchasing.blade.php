<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Purchasing Report</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9px;
            color: #111827;
            padding: 10mm;
        }
        h1 { font-size: 14px; margin-bottom: 2mm; }
        .generated { font-size: 8px; color: #6b7280; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; border-left: 1px solid #d1d5db; border-right: 1px solid #d1d5db; }
        th, td { padding: 2px 4px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { background: #fbbf24; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; }
        td.amount, th.amount { text-align: right; font-variant-numeric: tabular-nums; }
        tr.subtotal-row td { font-weight: bold; color: #059669; border-bottom: 2px solid #d1d5db; }
        td.supplier-name { font-weight: bold; color: #111827; border-right: 1px solid #d1d5db; }
        td.supplier-name.is-continuation { color: transparent; border-top: none; border-bottom: none; }
    </style>
</head>
<body>
    <h1>Supplier Purchasing Report</h1>
    <p class="generated">Generated {{ now()->format('d M Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Supplier Name</th>
                <th>Date</th>
                <th>Supplier Invoice No</th>
                <th>Invoice</th>
                <th class="amount">Net</th>
                <th class="amount">VAT</th>
                <th class="amount">Gross</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($suppliers as $supplier)
                @foreach($supplier['invoices'] as $invoice)
                    <tr>
                        <td class="supplier-name @if(! $loop->first) is-continuation @endif">{{ $supplier['company_name'] }} ({{ $supplier['reference'] }})</td>
                        <td>{{ $invoice['invoice_date'] }}</td>
                        <td>{{ $invoice['supplier_invoice_no'] }}</td>
                        <td>{{ $invoice['supplier_ref_invoice_no'] }}</td>
                        <td class="amount">£{{ number_format($invoice['net'], 2) }}</td>
                        <td class="amount">£{{ number_format($invoice['vat'], 2) }}</td>
                        <td class="amount">£{{ number_format($invoice['gross'], 2) }}</td>
                        <td>
                            @if($invoice['paid_status'] === 'paid')
                                <span style="color: #059669;">{{ ucfirst($invoice['paid_status']) }}</span>
                            @elseif($invoice['paid_status'] === 'partial')
                                <span style="color: #d97706;">{{ ucfirst($invoice['paid_status']) }}</span>
                            @else
                                <span style="color: #dc2626;">{{ ucfirst($invoice['paid_status']) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td class="supplier-name"></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="amount">£{{ number_format(array_sum(array_column($supplier['invoices'], 'net')), 2) }}</td>
                    <td class="amount">£{{ number_format(array_sum(array_column($supplier['invoices'], 'vat')), 2) }}</td>
                    <td class="amount">£{{ number_format(array_sum(array_column($supplier['invoices'], 'gross')), 2) }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

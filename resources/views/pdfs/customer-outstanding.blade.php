<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Outstanding Payments</title>
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
        td.customer-name { font-weight: bold; color: #111827; border-right: 1px solid #d1d5db; }
        td.customer-name.is-continuation { color: transparent; border-top: none; border-bottom: none; }
    </style>
</head>
<body>
    <h1>Customer Outstanding Payments</h1>
    <p class="generated">Generated {{ now()->format('d M Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Customer Name</th>
                <th>Date</th>
                <th>Invoice</th>
                <th class="amount">Total</th>
                <th class="amount">O/S</th>
            </tr>
        </thead>
        <tbody>
            @foreach($customers as $customer)
                @foreach($customer['invoices'] as $invoice)
                    <tr>
                        <td class="customer-name @if(! $loop->first) is-continuation @endif">{{ $customer['company_name'] }} ({{ $customer['reference'] }})</td>
                        <td>{{ $invoice['doc_date'] }}</td>
                        <td>{{ $invoice['doc_number'] }}</td>
                        <td class="amount">£{{ number_format($invoice['total_value'], 2) }}</td>
                        <td class="amount">£{{ number_format($invoice['outstanding'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td class="customer-name"></td>
                    <td></td>
                    <td></td>
                    <td class="amount">£{{ number_format(array_sum(array_column($customer['invoices'], 'total_value')), 2) }}</td>
                    <td class="amount">£{{ number_format(array_sum(array_column($customer['invoices'], 'outstanding')), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

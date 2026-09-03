<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Turnover</title>
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
        th { background: #4f46e5; color: #ffffff; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; }
        td.amount, th.amount { text-align: right; font-variant-numeric: tabular-nums; }
        td.customer-name { font-weight: bold; color: #111827; }
        tr.total-row td { font-weight: bold; color: #111827; border-top: 2px solid #d1d5db; border-bottom: 2px solid #d1d5db; }
    </style>
</head>
<body>
    <h1>Customer Turnover</h1>
    <p class="generated">Generated {{ now()->format('d M Y H:i') }}</p>

    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th class="amount">Invoices</th>
                <th class="amount">Total</th>
                @if($includeOutstanding)
                    <th class="amount">O/S</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td class="customer-name">{{ $row['company_name'] }} ({{ $row['reference'] }})</td>
                    <td class="amount">{{ number_format($row['invoice_count']) }}</td>
                    <td class="amount">£{{ number_format($row['total'], 2) }}</td>
                    @if($includeOutstanding)
                        <td class="amount">£{{ number_format($row['outstanding'] ?? 0, 2) }}</td>
                    @endif
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Grand Total</td>
                <td class="amount">{{ number_format($totals['invoiceCount']) }}</td>
                <td class="amount">£{{ number_format($totals['total'], 2) }}</td>
                @if($includeOutstanding)
                    <td class="amount">£{{ number_format($totals['outstanding'] ?? 0, 2) }}</td>
                @endif
            </tr>
        </tbody>
    </table>
</body>
</html>

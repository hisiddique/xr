<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $isReport ? 'Overhead Report' : 'Overheads' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9px;
            color: #111827;
            padding: 10mm;
        }
        h1 { font-size: 14px; margin-bottom: 2mm; }
        h2 { font-size: 11px; margin-bottom: 2mm; margin-top: 6mm; }
        .generated { font-size: 8px; color: #6b7280; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; border-left: 1px solid #d1d5db; border-right: 1px solid #d1d5db; }
        th, td { padding: 2px 4px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { background: #4f46e5; color: #ffffff; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; }
        td.amount, th.amount { text-align: right; font-variant-numeric: tabular-nums; }
        td.name { font-weight: bold; color: #111827; }
        tr.total-row td { font-weight: bold; color: #111827; border-top: 2px solid #d1d5db; border-bottom: 2px solid #d1d5db; }
    </style>
</head>
<body>
    <h1>{{ $isReport ? 'Overhead Expense & VAT Report' : 'Overheads' }}</h1>
    <p class="generated">Generated {{ now()->format('d M Y H:i') }}</p>

    @if($isReport)
        <h2>Cost Split Analysis by Category</h2>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="amount">Net Cost (Ex VAT)</th>
                    <th class="amount">Reclaimable VAT</th>
                    <th class="amount">Non-VAT Spend</th>
                    <th class="amount">Total Gross Cost</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary as $row)
                    <tr>
                        <td class="name">{{ $row['category'] }}</td>
                        <td class="amount">£{{ number_format($row['net_cost'], 2) }}</td>
                        <td class="amount">£{{ number_format($row['vat_portion'], 2) }}</td>
                        <td class="amount">£{{ number_format($row['non_vat_spend'], 2) }}</td>
                        <td class="amount">£{{ number_format($row['gross_total'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>Grand Total</td>
                    <td class="amount">£{{ number_format($summaryTotals['net_cost'], 2) }}</td>
                    <td class="amount">£{{ number_format($summaryTotals['vat_portion'], 2) }}</td>
                    <td class="amount">£{{ number_format($summaryTotals['non_vat_spend'], 2) }}</td>
                    <td class="amount">£{{ number_format($summaryTotals['gross_total'], 2) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Transactions</h2>
    @endif

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Category</th>
                @unless($isReport)
                    <th>Payment Method</th>
                @endunless
                <th>VAT Status</th>
                <th class="amount">Net</th>
                <th class="amount">VAT</th>
                <th class="amount">Gross</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td class="name">{{ $row['category'] }}</td>
                    @unless($isReport)
                        <td>{{ $row['payment_method'] }}</td>
                    @endunless
                    <td>{{ $row['vat_status'] }}</td>
                    <td class="amount">£{{ number_format($row['net'], 2) }}</td>
                    <td class="amount">£{{ number_format($row['vat'], 2) }}</td>
                    <td class="amount">£{{ number_format($row['gross'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Grand Total</td>
                <td></td>
                @unless($isReport)
                    <td></td>
                @endunless
                <td></td>
                <td class="amount">£{{ number_format($totals['net'], 2) }}</td>
                <td class="amount">£{{ number_format($totals['vat'], 2) }}</td>
                <td class="amount">£{{ number_format($totals['gross'], 2) }}</td>
            </tr>
        </tbody>
    </table>
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont("DejaVu Sans", "normal");
    $text = "Page {PAGE_NUM} of {PAGE_COUNT}";
    $pdf->page_text($pdf->get_width() - 28 - 56, 14, $text, $font, 8, [0.42, 0.45, 0.5]);
}
</script>
</body>
</html>

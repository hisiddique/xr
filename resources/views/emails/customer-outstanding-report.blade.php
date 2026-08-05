<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Outstanding Payments Report</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0; mso-table-rspace: 0; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }

        .wrapper { background-color: #f3f4f6; padding: 32px 16px; }

        .container {
            max-width: 560px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .header { background-color: #4f46e5; padding: 24px 32px; }
        .header-company { font-size: 18px; font-weight: bold; color: #ffffff; }

        .content { padding: 32px 32px 24px; }
        .greeting { font-size: 16px; color: #1f2937; margin: 0 0 16px; }
        .body-text { font-size: 14px; color: #4b5563; line-height: 1.6; margin: 0 0 24px; }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #f9fafb;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
        }
        .summary-table td { padding: 10px 16px; font-size: 13px; border-bottom: 1px solid #e5e7eb; }
        .summary-table tr:last-child td { border-bottom: none; }
        .summary-label { color: #6b7280; width: 60%; }
        .summary-value { color: #1f2937; font-weight: 600; }
        .summary-total .summary-label { color: #4f46e5; font-weight: 700; font-size: 14px; }
        .summary-total .summary-value { color: #4f46e5; font-weight: 700; font-size: 14px; font-family: monospace; }

        .closing { font-size: 14px; color: #4b5563; line-height: 1.6; margin: 0 0 8px; }
        .signature { font-size: 14px; font-weight: 600; color: #1f2937; margin: 0; }

        .footer { background-color: #f9fafb; border-top: 1px solid #e5e7eb; padding: 16px 32px; }
        .footer-text { font-size: 11px; color: #9ca3af; margin: 0; line-height: 1.6; }

        @media (prefers-color-scheme: dark) {
            body { background-color: #111827 !important; color: #f9fafb !important; }
            .wrapper { background-color: #111827 !important; }
            .container { background-color: #1f2937 !important; border-color: #374151 !important; }
            .header { background-color: #4338ca !important; }
            .content { background-color: #1f2937 !important; }
            .greeting { color: #f9fafb !important; }
            .body-text { color: #d1d5db !important; }
            .summary-table { background-color: #111827 !important; border-color: #374151 !important; }
            .summary-table td { border-color: #374151 !important; }
            .summary-label { color: #9ca3af !important; }
            .summary-value { color: #f9fafb !important; }
            .summary-total .summary-label { color: #818cf8 !important; }
            .summary-total .summary-value { color: #818cf8 !important; }
            .closing { color: #d1d5db !important; }
            .signature { color: #f9fafb !important; }
            .footer { background-color: #111827 !important; border-color: #374151 !important; }
            .footer-text { color: #6b7280 !important; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">

            <div class="header">
                <span class="header-company">{{ \App\Models\Setting::get('company_name', config('app.name')) }}</span>
            </div>

            <div class="content">
                <p class="greeting">Hi,</p>

                <p class="body-text">
                    Please find attached the Customer Outstanding Payments report from
                    <strong>{{ \App\Models\Setting::get('company_name', config('app.name')) }}</strong>,
                    generated {{ now()->format('d M Y H:i') }}.
                </p>

                <table class="summary-table">
                    <tr>
                        <td class="summary-label">Customers with an outstanding balance</td>
                        <td class="summary-value">{{ $customerCount }}</td>
                    </tr>
                    <tr class="summary-total">
                        <td class="summary-label">Total Outstanding</td>
                        <td class="summary-value">£{{ number_format($totalOutstanding, 2) }}</td>
                    </tr>
                </table>

                @if(! empty($notes))
                    <p class="body-text" style="white-space:pre-line">{{ $notes }}</p>
                @endif

                <p class="closing">The full report is attached as a PDF.</p>

                <p class="closing" style="margin-top:16px">Kind regards,</p>
                <p class="signature">{{ \App\Models\Setting::get('company_name', config('app.name')) }}</p>
            </div>

            <div class="footer">
                <p class="footer-text">
                    {{ \App\Models\Setting::get('company_name', config('app.name')) }}
                    @if(\App\Models\Setting::get('company_address'))
                        &mdash; {{ \App\Models\Setting::get('company_address') }}
                    @endif
                    @if(\App\Models\Setting::get('company_email'))
                        &mdash; {{ \App\Models\Setting::get('company_email') }}
                    @endif
                </p>
            </div>

        </div>
    </div>
</body>
</html>

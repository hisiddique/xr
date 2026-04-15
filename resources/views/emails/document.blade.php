<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $document->type->label() }} {{ $document->doc_number }}</title>
    <style>
        /* ── Reset ── */
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

        .wrapper {
            background-color: #f3f4f6;
            padding: 32px 16px;
        }

        .container {
            max-width: 560px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        /* ── Header bar ── */
        .header {
            background-color: #4f46e5;
            padding: 24px 32px;
        }
        .header-inner {
            width: 100%;
            border-collapse: collapse;
        }
        .header-logo {
            width: 40px;
            height: 40px;
            background-color: rgba(255,255,255,0.2);
            border-radius: 8px;
            text-align: center;
            line-height: 40px;
            font-size: 18px;
            font-weight: bold;
            color: #ffffff;
            display: inline-block;
        }
        .header-company {
            font-size: 18px;
            font-weight: bold;
            color: #ffffff;
            vertical-align: middle;
            padding-left: 12px;
        }

        /* ── Content ── */
        .content { padding: 32px 32px 24px; }

        .greeting { font-size: 16px; color: #1f2937; margin: 0 0 16px; }

        .body-text { font-size: 14px; color: #4b5563; line-height: 1.6; margin: 0 0 24px; }

        /* ── Summary card ── */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #f9fafb;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
        }
        .summary-table td {
            padding: 10px 16px;
            font-size: 13px;
            border-bottom: 1px solid #e5e7eb;
        }
        .summary-table tr:last-child td { border-bottom: none; }
        .summary-label { color: #6b7280; width: 40%; }
        .summary-value { color: #1f2937; font-weight: 600; }
        .summary-total .summary-label { color: #4f46e5; font-weight: 700; font-size: 14px; }
        .summary-total .summary-value { color: #4f46e5; font-weight: 700; font-size: 14px; font-family: monospace; }

        /* ── Closing ── */
        .closing { font-size: 14px; color: #4b5563; line-height: 1.6; margin: 0 0 8px; }
        .signature { font-size: 14px; font-weight: 600; color: #1f2937; margin: 0; }

        /* ── Footer ── */
        .footer {
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            padding: 16px 32px;
        }
        .footer-text { font-size: 11px; color: #9ca3af; margin: 0; line-height: 1.6; }

        /* ── Dark mode ── */
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

            {{-- Header --}}
            @php $emailLogoPath = \App\Models\Setting::get('company_logo_path'); @endphp
            <div class="header">
                <table class="header-inner">
                    <tr>
                        <td style="vertical-align:middle">
                            @if($emailLogoPath)
                                <img src="{{ asset('storage/'.$emailLogoPath) }}" style="max-height: 48px; display: block; margin-bottom: 12px;" alt="Company logo">
                            @endif
                            <table style="border-collapse:collapse">
                                <tr>
                                    @if(! $emailLogoPath)
                                        <td>
                                            <div class="header-logo">D</div>
                                        </td>
                                    @endif
                                    <td class="header-company">
                                        {{ \App\Models\Setting::get('company_name', config('app.name')) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>

            {{-- Content --}}
            <div class="content">
                <p class="greeting">
                    Hi {{ $document->customer->first_name ?? $document->customer->company_name }},
                </p>

                <p class="body-text">
                    Please find attached your {{ $document->type->label() }}
                    <strong>{{ $document->doc_number }}</strong>
                    from <strong>{{ \App\Models\Setting::get('company_name', config('app.name')) }}</strong>.
                    A summary is included below for your reference.
                </p>

                {{-- Summary table --}}
                <table class="summary-table">
                    <tr>
                        <td class="summary-label">{{ $document->type->label() }} Number</td>
                        <td class="summary-value" style="font-family:monospace">{{ $document->doc_number }}</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Date</td>
                        <td class="summary-value">{{ $document->doc_date->format('d M Y') }}</td>
                    </tr>
                    @if($document->order_no)
                        <tr>
                            <td class="summary-label">Order Reference</td>
                            <td class="summary-value">{{ $document->order_no }}</td>
                        </tr>
                    @endif
                    @if($document->discount_amount > 0)
                        <tr>
                            <td class="summary-label">Subtotal</td>
                            <td class="summary-value" style="font-family:monospace">£{{ number_format($document->subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="summary-label">Discount ({{ $document->trade_discount }}%)</td>
                            <td class="summary-value" style="font-family:monospace;color:#ef4444">-£{{ number_format($document->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="summary-label">VAT</td>
                        <td class="summary-value" style="font-family:monospace">£{{ number_format($document->vat_amount, 2) }}</td>
                    </tr>
                    <tr class="summary-total">
                        <td class="summary-label">Total</td>
                        <td class="summary-value" style="font-family:monospace">£{{ number_format($document->total_value, 2) }}</td>
                    </tr>
                </table>

                <p class="closing">
                    The {{ $document->type->label() }} PDF is attached to this email for your records.
                    If you have any questions, please do not hesitate to get in touch.
                </p>

                <p class="closing" style="margin-top:16px">Kind regards,</p>
                <p class="signature">{{ \App\Models\Setting::get('company_name', config('app.name')) }}</p>
            </div>

            {{-- Footer --}}
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

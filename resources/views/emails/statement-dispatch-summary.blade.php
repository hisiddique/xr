<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement dispatch summary</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0; mso-table-rspace: 0; }

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
        .heading { font-size: 16px; font-weight: 600; color: #1f2937; margin: 0 0 4px; }
        .status-line { font-size: 13px; color: #6b7280; margin: 0 0 24px; }
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

        .error-text {
            font-size: 13px;
            color: #b91c1c;
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px 16px;
            margin: 0 0 24px;
            line-height: 1.6;
            white-space: pre-line;
        }

        .failed-title { font-size: 13px; font-weight: 600; color: #1f2937; margin: 0 0 8px; }
        .failed-list { margin: 0 0 24px; padding: 0 0 0 18px; }
        .failed-list li { font-size: 12px; color: #4b5563; line-height: 1.6; margin-bottom: 4px; }

        .footer { background-color: #f9fafb; border-top: 1px solid #e5e7eb; padding: 16px 32px; }
        .footer-text { font-size: 11px; color: #9ca3af; margin: 0; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">

            <div class="header">
                <span class="header-company">{{ \App\Models\Setting::get('company_name', config('app.name')) }}</span>
            </div>

            <div class="content">
                <p class="heading">{{ $run->schedule_name }}</p>
                <p class="status-line">{{ $run->status->label() }}</p>

                <table class="summary-table">
                    <tr>
                        <td class="summary-label">Period</td>
                        <td class="summary-value">{{ $run->period_label }}</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Total</td>
                        <td class="summary-value">{{ $run->total_count }}</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Sent</td>
                        <td class="summary-value">{{ $run->sent_count }}</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Failed</td>
                        <td class="summary-value">{{ $run->failed_count }}</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Skipped</td>
                        <td class="summary-value">{{ $run->skipped_count }}</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Finished</td>
                        <td class="summary-value">{{ $run->finished_at?->format('d M Y H:i') ?? '—' }}</td>
                    </tr>
                </table>

                @if($run->error)
                    <p class="error-text">{{ $run->error }}</p>
                @endif

                @if($failedItems->isNotEmpty())
                    <p class="failed-title">Failed recipients</p>
                    <ul class="failed-list">
                        @foreach($failedItems as $item)
                            <li>{{ $item->recipient_name }} — {{ $item->recipient_email }} — {{ $item->error_message }}</li>
                        @endforeach
                    </ul>
                @endif
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

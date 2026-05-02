<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->type->label() }} {{ $document->doc_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #111827;
            background: #ffffff;
            padding: 10mm 10mm 10mm 10mm;
        }

        .doc {
            width: 100%;
            border-collapse: collapse;
            border: 1.25px solid #111827;
        }
        .doc td { border: 1px solid #111827; padding: 8px 10px; vertical-align: top; }

        /* Header row */
        .header-left { width: 50%; }
        .header-right { width: 50%; font-size: 10.5px; line-height: 1.55; }
        .company-name { font-size: 20px; font-weight: bold; line-height: 1.1; }
        .company-tagline { font-size: 11px; font-weight: bold; margin-top: 4px; color: #1f2937; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { border: none !important; padding: 1px 0 !important; font-size: 10.5px; }
        .kv td.k { width: 70px; font-weight: bold; }
        .kv td.c { width: 8px; }

        /* Meta row (customer + doc info) */
        .meta-left { width: 50%; min-height: 110px; font-size: 10.5px; line-height: 1.5; }
        .meta-right { width: 50%; font-size: 10.5px; line-height: 1.6; }
        .doc-title { text-align: center; font-weight: bold; font-size: 15px; margin-bottom: 6px; }
        .bill-company { font-weight: bold; font-size: 12px; margin-bottom: 2px; }
        .bill-name { font-size: 11px; color: #1f2937; margin-bottom: 2px; }
        .bill-addr { font-size: 10.5px; color: #374151; }

        /* Items table */
        .items { width: 100%; border-collapse: collapse; }
        .items th {
            background: #f3f4f6;
            border: 1px solid #111827;
            padding: 6px 8px;
            font-size: 10.5px;
            text-align: left;
            font-weight: bold;
        }
        .items th.right { text-align: right; }
        .items td {
            border: 1px solid #111827;
            padding: 6px 8px;
            font-size: 10.5px;
            vertical-align: top;
        }
        .items td.right { text-align: right; font-family: DejaVu Sans, Arial, sans-serif; }
        .items td.c { text-align: center; }
        .items .note-cell {
            font-style: italic;
            color: #374151;
            white-space: pre-wrap;
        }
        .items-wrap { border: 0; padding: 0 !important; }

        /* Bottom sections */
        .cert-box { font-size: 10.5px; line-height: 1.55; }
        .cert-title { font-weight: bold; font-size: 11.5px; margin-bottom: 6px; }
        .cert-iso { font-size: 10px; margin-bottom: 12px; }
        .sig-line { margin-top: 10px; }
        .sig-label { display: inline-block; width: 50px; }

        /* Signature tables: label cell + line cell */
        .sig-row { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .sig-row td { border: none !important; padding: 0 !important; font-size: 10.5px; vertical-align: bottom; }
        .sig-row td.lbl { width: 50px; font-weight: normal; padding-right: 4px !important; }
        .sig-row td.line { border-bottom: 1px dotted #111827 !important; height: 14px; }
        .sig-row td.gap { width: 12px; }

        .totals-box { width: 100%; border-collapse: collapse; }
        .totals-box td { border: none !important; padding: 4px 0 !important; font-size: 11px; }
        .totals-box td.l { font-weight: bold; }
        .totals-box td.v { text-align: right; font-family: DejaVu Sans, Arial, sans-serif; }
        .totals-box tr.grand td { border-top: 1.5px solid #111827 !important; padding-top: 6px !important; font-size: 12px; font-weight: bold; }

        .sig-grid-title { text-align: center; font-weight: bold; font-size: 10.5px; }

        .retention-text { font-size: 10.5px; line-height: 1.5; }
        .retention-text strong { font-weight: bold; }

        /* Footer */
        .footer {
            margin-top: 8px;
            font-size: 9.5px;
            color: #374151;
            line-height: 1.5;
        }
        .footer .director { font-weight: bold; margin-bottom: 2px; }
    </style>
</head>
<body>

@php
    $isDN = $document->type->value === 'DN';
    $showPricing = ! $isDN || (bool) $document->show_pricing;

    $companyName         = \App\Models\Setting::get('company_name', config('app.name'));
    $companyTagline      = \App\Models\Setting::get('company_tagline', '');
    $companyAddress      = \App\Models\Setting::get('company_address', '');
    $companyEmail        = \App\Models\Setting::get('company_email', '');
    $companyEmailAcc     = \App\Models\Setting::get('company_email_accounts', '');
    $companyTelSales     = \App\Models\Setting::get('company_tel_sales', '');
    $companyTelAcc       = \App\Models\Setting::get('company_tel_accounts', '');
    $companyDirector     = \App\Models\Setting::get('company_director', '');
    $companyRegNo        = \App\Models\Setting::get('company_registration_no', '');
    $companyVatNo        = \App\Models\Setting::get('company_vat_no', '');
    $companyIso          = \App\Models\Setting::get('company_iso_cert', '');
    $companyCertNo       = \App\Models\Setting::get('certificate_of_conformity_no', '');
    $companyRegAddress   = \App\Models\Setting::get('company_registered_address', '');
    $retentionClause     = \App\Models\Setting::get(
        'retention_of_title_clause',
        'By signing and accepting these goods you agree to our “RETENTION OF TITLE” terms and conditions, copy available on request. Loss, damage, discrepancy or non-delivery must be notified within five days of the Advice Note Date.'
    );

    $cust = $document->customer;
    $custPerson = trim(
        ($cust->title?->name ?? '').' '.
        ($cust->first_name ?? '').' '.
        ($cust->last_name ?? '')
    );
    $custAddressLines = collect([
        $cust->address_1,
        $cust->address_2,
        $cust->town,
        $cust->post_code,
    ])->filter()->values();
@endphp

<table class="doc">
    {{-- ── Row 1: Company header ── --}}
    <tr>
        <td class="header-left">
            <div class="company-name">{{ $companyName }}</div>
            @if($companyTagline)
                <div class="company-tagline">{{ $companyTagline }}</div>
            @endif
        </td>
        <td class="header-right">
            @if($companyAddress)
                {!! nl2br(e($companyAddress)) !!}
                <div style="height: 6px"></div>
            @endif
            <table class="kv">
                @if($companyTelSales)
                    <tr><td class="k">Tel Sales</td><td class="c">:</td><td>{{ $companyTelSales }}</td></tr>
                @endif
                @if($companyTelAcc)
                    <tr><td class="k">Accounts</td><td class="c">:</td><td>{{ $companyTelAcc }}</td></tr>
                @endif
                @if($companyEmail)
                    <tr><td class="k">E-Mail</td><td class="c">:</td><td>{{ $companyEmail }}</td></tr>
                @endif
                @if($companyEmailAcc)
                    <tr><td class="k">E-Mail</td><td class="c">:</td><td>{{ $companyEmailAcc }}</td></tr>
                @endif
            </table>
        </td>
    </tr>

    {{-- ── Row 2: Customer + document meta ── --}}
    <tr>
        <td class="meta-left">
            @if($cust->company_name)
                <div class="bill-company">{{ $cust->company_name }}</div>
            @endif
            @if($custPerson)
                <div class="bill-name">{{ $custPerson }}</div>
            @endif
            <div class="bill-addr">
                @foreach($custAddressLines as $line)
                    {{ $line }}@if(! $loop->last)<br>@endif
                @endforeach
            </div>
        </td>
        <td class="meta-right">
            <div class="doc-title">{{ $isDN ? 'Delivery Note' : 'Invoice' }}</div>
            <table class="kv">
                <tr><td class="k">Date</td><td class="c">:</td><td>{{ $document->doc_date->format('d/m/Y') }}</td></tr>
                <tr><td class="k">{{ $isDN ? 'Delivery Note No.' : 'Invoice No.' }}</td><td class="c">:</td><td>{{ $document->doc_number }}</td></tr>
                <tr><td class="k">Customer</td><td class="c">:</td><td>{{ $cust->company_name }}</td></tr>
                @if($document->order_no)
                    <tr><td class="k">Order No</td><td class="c">:</td><td>{{ $document->order_no }}</td></tr>
                @endif
                <tr><td class="k">Sheet No.</td><td class="c">:</td><td>1</td></tr>
            </table>
        </td>
    </tr>

    {{-- ── Row 3: Line items ── --}}
    <tr>
        <td class="items-wrap" colspan="2">
            <table class="items">
                <thead>
                    <tr>
                        <th>Details</th>
                        <th class="right" style="width:10%">Qty</th>
                        @if($showPricing)
                            <th class="right" style="width:12%">Price</th>
                        @endif
                        <th style="width:8%">Per</th>
                        @if($showPricing)
                            <th class="right" style="width:14%">Value</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($document->items as $item)
                        @if($item->is_note)
                            <tr>
                                <td colspan="{{ $showPricing ? 5 : 3 }}" class="note-cell">{{ $item->details }}</td>
                            </tr>
                        @else
                            <tr>
                                <td>{{ $item->details }}</td>
                                <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') }}</td>
                                @if($showPricing)
                                    <td class="right">{{ number_format($item->price, 2) }}</td>
                                @endif
                                <td class="c">{{ $item->per ?? '' }}</td>
                                @if($showPricing)
                                    <td class="right">{{ number_format($item->line_value, 2) }}</td>
                                @endif
                            </tr>
                        @endif
                    @endforeach

                    {{-- Spacer rows to keep a consistent visual height for short line-item lists --}}
                    @for($i = $document->items->count(); $i < 6; $i++)
                        <tr><td colspan="{{ $showPricing ? 5 : 3 }}" style="height: 18px">&nbsp;</td></tr>
                    @endfor
                </tbody>
            </table>
        </td>
    </tr>

    {{-- ── Row 4: Certificate of Conformity + Totals (invoices) ── --}}
    <tr>
        <td class="cert-box">
            <div class="cert-title">CERTIFICATE OF CONFORMITY @if($companyCertNo) {{ $companyCertNo }} @endif</div>
            @if($companyIso)
                <div class="cert-iso">The following goods conform to specification of your order and have been supplied accordingly ({{ $companyIso }})</div>
            @else
                <div class="cert-iso">The following goods conform to specification of your order and have been supplied accordingly.</div>
            @endif
            <table class="sig-row">
                <tr>
                    <td class="lbl">Date:</td>
                    <td class="line"></td>
                </tr>
            </table>
            <table class="sig-row" style="margin-top: 14px">
                <tr>
                    <td class="lbl">Signed:</td>
                    <td class="line"></td>
                    <td class="gap"></td>
                    <td class="lbl">Printed:</td>
                    <td class="line"></td>
                </tr>
            </table>
        </td>
        <td class="cert-box">
            @if(! $showPricing)
                &nbsp;
            @else
                <table class="totals-box">
                    <tr>
                        <td class="l">Total Value</td>
                        <td class="v">£{{ number_format($document->subtotal, 2) }}</td>
                    </tr>
                    @if($document->discount_amount > 0)
                        <tr>
                            <td class="l">Discount ({{ $document->trade_discount }}%)</td>
                            <td class="v">-£{{ number_format($document->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="l">VAT @ {{ number_format((float) \App\Models\Setting::get('vat_rate', 20), 2) }}%</td>
                        <td class="v">£{{ number_format($document->vat_amount, 2) }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="l">{{ $isDN ? 'Total' : 'Invoice Total' }}</td>
                        <td class="v">£{{ number_format($document->total_value, 2) }}</td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>

    {{-- ── Row 5: Stores / Driver signatures ── --}}
    <tr>
        <td>
            <div class="sig-grid-title">{{ $companyName }} &mdash; Stores</div>
            <table class="sig-row" style="margin-top: 6px">
                <tr>
                    <td class="lbl">Signed:</td>
                    <td class="line"></td>
                    <td class="gap"></td>
                    <td class="lbl">Printed:</td>
                    <td class="line"></td>
                </tr>
            </table>
        </td>
        <td>
            <div class="sig-grid-title">{{ $companyName }} &mdash; Driver</div>
            <table class="sig-row" style="margin-top: 6px">
                <tr>
                    <td class="lbl">Signed:</td>
                    <td class="line"></td>
                    <td class="gap"></td>
                    <td class="lbl">Printed:</td>
                    <td class="line"></td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- ── Row 6: Retention clause + customer signature ── --}}
    <tr>
        <td class="retention-text">
            {!! preg_replace('/"([^"]+)"/', '<strong>$1</strong>', e($retentionClause)) !!}
        </td>
        <td>
            <table class="sig-row">
                <tr>
                    <td class="lbl">Signed:</td>
                    <td class="line"></td>
                </tr>
            </table>
            <table class="sig-row" style="margin-top: 14px">
                <tr>
                    <td class="lbl">Printed:</td>
                    <td class="line"></td>
                    <td class="gap"></td>
                    <td class="lbl">Date:</td>
                    <td class="line"></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── Footer ── --}}
<div class="footer">
    @if($companyDirector)
        <div class="director">Director: {{ $companyDirector }}</div>
    @endif
    @if($companyRegNo || $companyVatNo)
        <div>
            @if($companyRegNo)Registered in England: {{ $companyRegNo }}@endif
            @if($companyRegNo && $companyVatNo), @endif
            @if($companyVatNo)VAT No. {{ $companyVatNo }}@endif
        </div>
    @endif
    @if($companyRegAddress)
        <div>{{ $companyRegAddress }}</div>
    @endif
</div>

</body>
</html>

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
            line-height: 1.15;
            padding: 10mm 10mm 32mm 10mm;
        }

        .doc {
            width: 100%;
            border-collapse: collapse;
        }
        .doc > thead > tr > td,
        .doc > tbody > tr > td {
            border: 1px solid #111827;
            padding: 7px 9px;
            vertical-align: top;
        }
        /* Thicker outer perimeter (matches original .doc { border: 1.25px }) */
        .doc > thead > tr:first-child > td,
        .doc > thead > tr:first-child > th { border-top: 1.25px solid #111827; }
        .doc > tbody > tr:last-child > td,
        .doc > tbody > tr:last-child > th { border-bottom: 1.25px solid #111827; }
        .doc > thead > tr > td:first-child,
        .doc > thead > tr > th:first-child,
        .doc > tbody > tr > td:first-child,
        .doc > tbody > tr > th:first-child { border-left: 1.25px solid #111827; }
        .doc > thead > tr > td:last-child,
        .doc > thead > tr > th:last-child,
        .doc > tbody > tr > td:last-child,
        .doc > tbody > tr > th:last-child { border-right: 1.25px solid #111827; }
        /* Chrome rows host a nested split table; remove outer padding so the
           inner vertical border reaches the full row height. */
        .doc td.split-cell { padding: 0 !important; }

        /* Inner 2-col layout table for chrome rows */
        .split { width: 100%; border-collapse: collapse; }
        .split > tbody > tr > td {
            width: 50%;
            border: none;
            padding: 7px 9px;
            vertical-align: top;
        }
        .split > tbody > tr > td.left { border-right: 1px solid #111827; }

        /* Header band */
        .company-name { font-size: 19px; font-weight: bold; line-height: 1.05; }
        .company-tagline { font-size: 11px; font-weight: bold; margin-top: 2px; color: #1f2937; }
        .header-right { font-size: 10.5px; line-height: 1.15; }

        .kv { width: 100%; border-collapse: collapse; }
        .kv td { border: none; padding: 1px 0; font-size: 10.5px; line-height: 1.2; }
        .kv td.k { width: 78px; font-weight: bold; }
        .kv td.c { width: 8px; }

        /* Customer + doc-meta band */
        .meta-left { font-size: 10.5px; line-height: 1.15; }
        .meta-right { font-size: 10.5px; line-height: 1.15; }
        .doc-title { text-align: center; font-weight: bold; font-size: 15px; margin-bottom: 4px; line-height: 1.1; }
        .bill-company { font-weight: bold; font-size: 12px; margin-bottom: 1px; line-height: 1.15; }
        .bill-name { font-size: 11px; color: #1f2937; margin-bottom: 1px; line-height: 1.15; }
        .bill-addr { font-size: 10.5px; color: #374151; line-height: 1.2; }

        /* Items table — same table as .doc */
        .doc th {
            background: #f3f4f6;
            border: 1px solid #111827;
            padding: 5px 8px;
            font-size: 10.5px;
            text-align: left;
            font-weight: bold;
        }
        /* Borderless spacer — visible whitespace between chrome and items.
           No borders on any side; chrome row above and items header below
           each contribute their own borders to bracket the gap. */
        tr.spacer > td {
            border: none !important;
            padding: 0 !important;
            height: 6px;
            background: transparent;
        }
        .doc th.right { text-align: right; }
        .item-cell { padding: 6px 9px !important; line-height: 1.2; }
        .item-cell.right { text-align: right; }
        .item-cell.c { text-align: center; }
        .note-cell {
            font-style: italic;
            color: #374151;
            white-space: pre-wrap;
            padding: 5px 8px !important;
        }


        /* End-of-doc blocks */
        .cert-box { font-size: 10.5px; line-height: 1.2; }
        .cert-title { font-weight: bold; font-size: 11.5px; margin-bottom: 3px; }
        .cert-iso { font-size: 10px; margin-bottom: 6px; line-height: 1.2; }

        .sig-row { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .sig-row td { border: none !important; padding: 0 !important; font-size: 10.5px; vertical-align: bottom; }
        .sig-row td.lbl { width: 50px; font-weight: normal; padding-right: 4px !important; }
        .sig-row td.line { border-bottom: 1px dotted #111827 !important; height: 12px; }
        .sig-row td.gap { width: 12px; }

        .totals-box { width: 100%; border-collapse: collapse; }
        .totals-box td { border: none !important; padding: 2px 0 !important; font-size: 11px; line-height: 1.15; }
        .totals-box td.l { font-weight: bold; }
        .totals-box td.v { text-align: right; }
        .totals-box tr.grand td { border-top: 1.5px solid #111827 !important; padding-top: 4px !important; font-size: 12px; font-weight: bold; }

        .sig-grid-title { text-align: center; font-weight: bold; font-size: 10.5px; }
        .retention-text { font-size: 10.5px; line-height: 1.2; }
        .retention-text strong { font-weight: bold; }

        /* Fixed page footer — same look as original .footer, just pinned. */
        .page-footer {
            position: fixed;
            left: 10mm;
            right: 10mm;
            bottom: 8mm;
            font-size: 9.5px;
            color: #374151;
            line-height: 1.5;
        }
        .page-footer .director { font-weight: bold; margin-bottom: 2px; }
    </style>
</head>
<body>

@php
    $isDN = $document->type->value === 'DN';
    $showPricing = ! $isDN || (bool) $document->show_pricing;
    $cols = $showPricing ? 5 : 3;

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
    <thead>
        {{-- Row 1: company header (repeats on every page) --}}
        <tr>
            <td colspan="{{ $cols }}" class="split-cell">
                <table class="split"><tr>
                    <td class="left">
                        <div class="company-name">{{ $companyName }}</div>
                        @if($companyTagline)
                            <div class="company-tagline">{{ $companyTagline }}</div>
                        @endif
                    </td>
                    <td class="right header-right">
                        @if($companyAddress)
                            {!! nl2br(e($companyAddress)) !!}
                        @endif
                        <table class="kv" style="margin-top: 2px">
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
                </tr></table>
            </td>
        </tr>

        {{-- Row 2: customer + doc meta (repeats; Sheet No. dynamic) --}}
        <tr>
            <td colspan="{{ $cols }}" class="split-cell">
                <table class="split"><tr>
                    <td class="left meta-left">
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
                    <td class="right meta-right">
                        <div class="doc-title">{{ $isDN ? 'Delivery Note' : 'Invoice' }}</div>
                        <table class="kv">
                            <tr><td class="k">Date</td><td class="c">:</td><td>{{ $document->doc_date->format('d/m/Y') }}</td></tr>
                            <tr><td class="k">{{ $isDN ? 'Delivery Note No.' : 'Invoice No.' }}</td><td class="c">:</td><td>{{ $document->doc_number }}</td></tr>
                            <tr><td class="k">Customer</td><td class="c">:</td><td>{{ $cust->company_name }}</td></tr>
                            @if($document->order_no)
                                <tr><td class="k">Order No</td><td class="c">:</td><td>{{ $document->order_no }}</td></tr>
                            @endif
                            <tr><td class="k">Sheet No.</td><td class="c">:</td><td>&nbsp;</td></tr>
                        </table>
                    </td>
                </tr></table>
            </td>
        </tr>

        {{-- Spacer: borderless gap between chrome and items table.
             Stays inside thead so it repeats with chrome on every page. --}}
        <tr class="spacer"><td colspan="{{ $cols }}">&nbsp;</td></tr>
    </thead>

    {{-- Items body --}}
    <tbody>
        {{-- Items column header — inside tbody so it appears once, not on the
             end-of-doc page where there are no items below it. --}}
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

        @foreach($document->items as $item)
            @if($item->is_note)
                <tr>
                    <td colspan="{{ $cols }}" class="note-cell">{{ $item->details }}</td>
                </tr>
            @else
                <tr>
                    <td class="item-cell">{{ $item->details }}</td>
                    <td class="item-cell right">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') }}</td>
                    @if($showPricing)
                        <td class="item-cell right">{{ number_format($item->price, 2) }}</td>
                    @endif
                    <td class="item-cell c">{{ $item->per ?? '' }}</td>
                    @if($showPricing)
                        <td class="item-cell right">{{ number_format($item->line_value, 2) }}</td>
                    @endif
                </tr>
            @endif
        @endforeach

        @for($i = $document->items->count(); $i < 6; $i++)
            <tr><td colspan="{{ $cols }}" style="height: 16px">&nbsp;</td></tr>
        @endfor
    </tbody>

    {{-- End-of-document blocks (appear once after items) --}}
    <tbody>
        <tr>
            <td colspan="{{ $cols }}" class="split-cell">
                <table class="split"><tr>
                    <td class="left cert-box">
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
                        <table class="sig-row" style="margin-top: 8px">
                            <tr>
                                <td class="lbl">Signed:</td>
                                <td class="line"></td>
                                <td class="gap"></td>
                                <td class="lbl">Printed:</td>
                                <td class="line"></td>
                            </tr>
                        </table>
                    </td>
                    <td class="right cert-box">
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
                </tr></table>
            </td>
        </tr>

        <tr>
            <td colspan="{{ $cols }}" class="split-cell">
                <table class="split"><tr>
                    <td class="left">
                        <div class="sig-grid-title">{{ $companyName }} &mdash; Stores</div>
                        <table class="sig-row" style="margin-top: 4px">
                            <tr>
                                <td class="lbl">Signed:</td>
                                <td class="line"></td>
                                <td class="gap"></td>
                                <td class="lbl">Printed:</td>
                                <td class="line"></td>
                            </tr>
                        </table>
                    </td>
                    <td class="right">
                        <div class="sig-grid-title">{{ $companyName }} &mdash; Driver</div>
                        <table class="sig-row" style="margin-top: 4px">
                            <tr>
                                <td class="lbl">Signed:</td>
                                <td class="line"></td>
                                <td class="gap"></td>
                                <td class="lbl">Printed:</td>
                                <td class="line"></td>
                            </tr>
                        </table>
                    </td>
                </tr></table>
            </td>
        </tr>

        <tr>
            <td colspan="{{ $cols }}" class="split-cell">
                <table class="split"><tr>
                    <td class="left retention-text">
                        {!! preg_replace('/"([^"]+)"/', '<strong>$1</strong>', e($retentionClause)) !!}
                    </td>
                    <td class="right">
                        <table class="sig-row">
                            <tr>
                                <td class="lbl">Signed:</td>
                                <td class="line"></td>
                            </tr>
                        </table>
                        <table class="sig-row" style="margin-top: 8px">
                            <tr>
                                <td class="lbl">Printed:</td>
                                <td class="line"></td>
                                <td class="gap"></td>
                                <td class="lbl">Date:</td>
                                <td class="line"></td>
                            </tr>
                        </table>
                    </td>
                </tr></table>
            </td>
        </tr>
    </tbody>
</table>

{{-- Per-page Sheet No. drawn via inline PHP script (dompdf's only reliable
     way to substitute page number/count — CSS counter() inside <thead>
     accumulates digits across pages). Coordinates target the empty value
     cell in the customer-meta row of the chrome. --}}
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont("DejaVu Sans", "normal");
    $pdf->page_text(380, 195, "{PAGE_NUM}", $font, 7.875);
}
</script>

{{-- Fixed page footer (paints on every page) --}}
<div class="page-footer">
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

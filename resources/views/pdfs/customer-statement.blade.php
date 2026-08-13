<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #111827;
            background: #ffffff;
            line-height: 1.15;
            padding: 62mm 10mm 22mm 10mm;
        }

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
        .kv td.k { width: 60px; font-weight: bold; white-space: nowrap; }
        .kv td.c { width: 8px; }

        /* Customer + statement-meta band */
        .meta-left { font-size: 10.5px; line-height: 1.15; }
        .meta-right { font-size: 10.5px; line-height: 1.15; }
        .doc-title { text-align: center; font-weight: bold; font-size: 15px; margin-bottom: 4px; line-height: 1.1; }
        .bill-company { font-weight: bold; font-size: 12px; margin-bottom: 1px; line-height: 1.15; }
        .bill-name { font-size: 11px; color: #1f2937; margin-bottom: 1px; line-height: 1.15; }
        .bill-addr { font-size: 10.5px; color: #374151; line-height: 1.2; }

        /* Fixed page header — chrome (company + customer/meta bands) */
        .page-header {
            position: fixed;
            top: 10mm;
            left: 10mm;
            right: 10mm;
        }
        .chrome {
            width: 100%;
            border-collapse: collapse;
        }
        .chrome > tbody > tr > td {
            border: 1px solid #111827;
            padding: 0;
            vertical-align: top;
        }
        .chrome > tbody > tr:first-child > td { border-top: 1.25px solid #111827; }
        .chrome > tbody > tr:last-child > td { border-bottom: 1.25px solid #111827; }
        .chrome > tbody > tr > td:first-child { border-left: 1.25px solid #111827; }
        .chrome > tbody > tr > td:last-child { border-right: 1.25px solid #111827; }
        .chrome > tbody > tr.headline > td { border: none !important; }
        .chrome > tbody > tr.headline .split td.left { border-right: none !important; }

        /* Content box rails — fills the area from items down to the aging block on every page. */
        .content-frame {
            position: fixed;
            left: 10mm;
            right: 10mm;
            top: 62mm;
            bottom: 47mm;
            border-left: 1.25px solid #111827;
            border-right: 1.25px solid #111827;
            border-bottom: 1.25px solid #111827;
        }

        /* Items table — standalone flowing table; thead repeats on every page */
        .items {
            width: 100%;
            border-collapse: collapse;
        }
        .items th {
            background: #f3f4f6;
            border: 1px solid #111827;
            padding: 5px 8px;
            font-size: 10.5px;
            text-align: left;
            font-weight: bold;
        }
        .items th.right { text-align: right; }
        .items td {
            border: 1px solid #111827;
            padding: 7px 9px;
            vertical-align: top;
        }
        .item-cell { padding: 6px 9px !important; line-height: 1.2; font-size: 10.5px; }
        .item-cell.right { text-align: right; }
        .item-cell.bold { font-weight: bold; }

        /* Aging summary block — glued to the page bottom (same fixed-position pattern as the
           totals box in pdfs/document.blade.php), sitting in its own bordered box below the
           items table rather than flowing inside it. */
        .bottom-block {
            position: fixed;
            left: 10mm;
            right: 10mm;
            bottom: 22mm;
            height: 24mm;
        }
        .aging-box {
            width: 100%;
            border-collapse: collapse;
        }
        .aging-box > tbody > tr > td {
            border: 1px solid #111827;
            padding: 0;
            vertical-align: top;
        }
        .aging-header {
            padding: 6px 9px !important;
            border-bottom: 1px solid #111827 !important;
        }
        .aging-header table { width: 100%; border-collapse: collapse; }
        .aging-header td { border: none !important; padding: 0 !important; font-size: 11.5px; font-weight: bold; }
        .aging-header td.right { text-align: right; }

        .aging-cols { width: 100%; border-collapse: collapse; }
        .aging-cols td {
            border: none !important;
            border-right: 1px solid #d1d5db !important;
            width: 20%;
            text-align: center;
            padding: 6px 4px !important;
            vertical-align: top;
        }
        .aging-cols td:last-child { border-right: none !important; }
        .aging-label { font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.02em; color: #374151; margin-bottom: 2px; }
        .aging-amount { font-size: 11px; }

        /* Fixed page footer — legal/company info only, no page number here */
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
@php
    $letterhead = app(\App\Services\CompanyLetterheadService::class);
    $header = $letterhead->header();
    $footer = $letterhead->footer();

    // Same fixed-height-header technique as pdfs/document.blade.php: the page
    // number below is drawn via dompdf's page_text() at fixed coordinates, which
    // can't track dynamic content height, so CompanyLetterheadService reserves a
    // constant number of address lines and this template only needs to adjust for
    // the rare case where the address overflows that reservation.
    $rowLinePx = 14; // ≈ rendered height (px) of one kv/address line
    $headlineExtraPx = $header['addressOverflowLines'] * $rowLinePx;
    $headlineExtraMm = $headlineExtraPx * 25.4 / 96;

    $custPerson = $customer ? trim(
        ($customer->title?->name ?? '').' '.
        ($customer->first_name ?? '').' '.
        ($customer->last_name ?? '')
    ) : '';
    $custAddressLines = $customer ? collect([
        $customer->address_1,
        $customer->address_2,
        $customer->town,
        $customer->post_code,
    ])->filter()->values() : collect();

    $pageNumX = 358;
    $pageNumY = 184 + $headlineExtraPx;
    $headerTopMm = 80 + $headlineExtraMm;
@endphp

<body style="padding-top: {{ $headerTopMm }}mm; padding-bottom: 47mm">

{{-- Fixed page header: company chrome + customer/statement-meta bands --}}
<div class="page-header">
    <table class="chrome">
        <tbody>
            <x-pdf.letterhead-header
                :name="$header['name']"
                :tagline="$header['tagline']"
                :address-lines="$header['addressLines']"
                :kv-rows="$header['kvRows']"
            />
            <tr>
                <td>
                    <table class="split"><tr>
                        <td class="left meta-left">
                            @if($customer)
                                @if($customer->company_name)
                                    <div class="bill-company">{{ $customer->company_name }}</div>
                                @endif
                                @if($custPerson)
                                    <div class="bill-name">{{ $custPerson }}</div>
                                @endif
                                <div class="bill-addr">
                                    @foreach($custAddressLines as $line)
                                        {{ $line }}@if(! $loop->last)<br>@endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="right meta-right">
                            <div class="doc-title">Statement</div>
                            <table class="kv">
                                <tr><td class="k">Account</td><td class="c">:</td><td>{{ $customer->reference ?? '—' }}</td></tr>
                                <tr><td class="k">Date</td><td class="c">:</td><td>{{ now()->format('d.m.y') }}</td></tr>
                                <tr><td class="k">Sheet</td><td class="c">:</td><td>&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr></table>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- Content box rails — fill the area down to the aging block on every page --}}
<div class="content-frame" style="top: {{ $headerTopMm }}mm"></div>

{{-- Standalone items table; thead repeats on every page --}}
<table class="items">
    <thead>
        <tr>
            <th style="width:12%">Date</th>
            <th>Details</th>
            <th class="right" style="width:16%">Total Value</th>
            <th class="right" style="width:14%">Credit</th>
            <th class="right" style="width:16%">Amount Due</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoices as $invoice)
            @php($rowType = $invoice['row_type'] ?? 'invoice')
            <tr>
                <td class="item-cell">{{ $invoice['doc_date'] ? \Carbon\Carbon::parse($invoice['doc_date'])->format('d.m.y') : '' }}</td>
                <td class="item-cell">
                    @if($rowType === 'credit_note')
                        Credit Note {{ trim($invoice['doc_number'].' '.($invoice['order_no'] ? '(against '.$invoice['order_no'].')' : '')) }}
                    @elseif($rowType === 'payment')
                        Payment {{ trim($invoice['doc_number'].' '.($invoice['order_no'] ?? '')) }}
                    @else
                        {{ trim($invoice['doc_number'].' '.($invoice['order_no'] ?? '')) }}
                    @endif
                </td>
                <td class="item-cell right">{{ $invoice['total_value'] > 0 ? '£'.number_format($invoice['total_value'], 2) : '' }}</td>
                <td class="item-cell right">{{ $invoice['credited'] > 0 ? '£'.number_format($invoice['credited'], 2) : '' }}</td>
                <td class="item-cell right bold">{{ $rowType === 'invoice' ? '£'.number_format($invoice['outstanding'], 2) : '' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- Aging summary — glued to the page bottom in its own box, like the totals
     box in pdfs/document.blade.php, rather than flowing inside the items table. --}}
<div class="bottom-block">
    <table class="aging-box">
        <tbody>
            <tr>
                <td class="aging-header">
                    <table>
                        <tr>
                            <td>Overdue Amount:</td>
                            <td class="right">Totals: £{{ number_format($aging['total'], 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr>
                <td>
                    <table class="aging-cols">
                        <tr>
                            @foreach($aging['labels'] as $label => $amount)
                                <td>
                                    <div class="aging-label">{{ $label }}</div>
                                    <div class="aging-amount">£{{ number_format($amount, 2) }}</div>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- Per-page Sheet No. drawn via inline PHP script into the empty value cell
     of the Sheet row in the header meta band — matches the document.blade.php
     convention of putting the page indicator in the letterhead, not the footer. --}}
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont("DejaVu Sans", "normal");
    $pdf->page_text({{ $pageNumX }}, {{ $pageNumY }}, "{PAGE_NUM}", $font, 7.875);
}
</script>

{{-- Fixed page footer (paints on every page) --}}
<x-pdf.letterhead-footer
    :director="$footer['director']"
    :reg-no="$footer['regNo']"
    :vat-no="$footer['vatNo']"
    :reg-address="$footer['regAddress']"
/>

</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Debit Note {{ $debitNote->reference }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #111827;
            background: #ffffff;
            line-height: 1.15;
            padding: 77mm 10mm 65mm 10mm;
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
        .kv td.k { width: 112px; font-weight: bold; white-space: nowrap; }
        .kv td.c { width: 8px; }

        /* Supplier + doc-meta band */
        .meta-left { font-size: 10.5px; line-height: 1.15; }
        .meta-right { font-size: 10.5px; line-height: 1.15; }
        .doc-title { text-align: center; font-weight: bold; font-size: 15px; margin-bottom: 4px; line-height: 1.1; }
        .bill-company { font-weight: bold; font-size: 12px; margin-bottom: 1px; line-height: 1.15; }
        .bill-name { font-size: 11px; color: #1f2937; margin-bottom: 1px; line-height: 1.15; }
        .bill-addr { font-size: 10.5px; color: #374151; line-height: 1.2; }

        /* Fixed page header — chrome (company + supplier/meta bands) */
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

        /* Content box rails — fills the area from items down to the footer on
           every page so short docs show no gap. */
        .content-frame {
            position: fixed;
            left: 10mm;
            right: 10mm;
            top: 77mm;
            bottom: 65mm;
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
        .item-cell { padding: 6px 9px !important; line-height: 1.2; }
        .item-cell.right { text-align: right; }
        .item-cell.c { text-align: center; }

        /* End-of-doc blocks */
        .cert-box { font-size: 10.5px; line-height: 1.2; }
        .cert-title { font-weight: bold; font-size: 11.5px; margin-bottom: 3px; }

        .totals-box { width: 100%; border-collapse: collapse; }
        .totals-box td { border: none !important; padding: 2px 0 !important; font-size: 11px; line-height: 1.15; }
        .totals-box td.l { font-weight: bold; }
        .totals-box td.v { text-align: right; }
        .totals-box tr.grand td { border-top: 1.5px solid #111827 !important; padding-top: 4px !important; font-size: 12px; font-weight: bold; }

        /* Bottom block — notes/totals glued to bottom on every page. */
        .bottom-block {
            position: fixed;
            left: 10mm;
            right: 10mm;
            bottom: 26mm;
            height: 38mm;
        }
        .doc-bottom {
            width: 100%;
            border-collapse: collapse;
        }
        .doc-bottom > tbody > tr > td {
            border: 1px solid #111827;
            padding: 7px 9px;
            vertical-align: top;
        }
        .doc-bottom > tbody > tr:first-child > td { border-top: 1.25px solid #111827; }
        .doc-bottom > tbody > tr:last-child > td { border-bottom: 1.25px solid #111827; }
        .doc-bottom > tbody > tr > td:first-child { border-left: 1.25px solid #111827; }
        .doc-bottom > tbody > tr > td:last-child { border-right: 1.25px solid #111827; }
        .doc-bottom td.split-cell { padding: 0 !important; }

        /* Fixed page footer */
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
    $footer = $letterhead->footer(\App\Models\Setting::get('company_director', ''));

    $supplier = $debitNote->supplier;
    $supplierPerson = trim(
        ($supplier->title?->name ?? '').' '.
        ($supplier->first_name ?? '').' '.
        ($supplier->last_name ?? '')
    );
    $supplierAddressLines = collect([
        $supplier->address_line_1,
        $supplier->address_line_2,
        $supplier->town_city,
        $supplier->post_code,
    ])->filter()->values();

    $supplierInvoice = $debitNote->supplierInvoice;
    $againstInvoiceNo = $supplierInvoice?->supplier_invoice_no;
    $supplierRefNo = trim((string) ($supplierInvoice?->supplier_ref_invoice_no ?? '')) ?: null;

    // The "Sheet No." page number is drawn via dompdf's page_text() at fixed
    // coordinates, which can't track dynamic content height. The header height is
    // held constant by the letterhead service reserving a fixed line count; only
    // the conditional "Against Invoice" row and rare address overflow shift it.
    $rowLinePx = 14;
    $headlineExtraPx = $header['addressOverflowLines'] * $rowLinePx;
    $headlineExtraMm = $headlineExtraPx * 25.4 / 96;

    $pageNumX = 393;
    $pageNumY = 197
        + ($againstInvoiceNo ? 14 : 0)
        + ($supplierRefNo ? 14 : 0)
        + $headlineExtraPx;

    $metaCharsPerLine = 56;
    $visualLines = fn (?string $text) => $text ? max(1, (int) ceil(mb_strlen($text) / $metaCharsPerLine)) : 0;
    $leftMetaLines = $visualLines($supplier->company_name)
        + $visualLines($supplierPerson)
        + $supplierAddressLines->sum($visualLines)
        + ($supplier->supplier_vat_number ? 1 : 0);
    $rightMetaLines = 6 + ($againstInvoiceNo ? 1 : 0) + ($supplierRefNo ? 1 : 0);
    $headerTopMm = 77 + max(0, max($leftMetaLines, $rightMetaLines) - 6) * 4.0 + $headlineExtraMm;
@endphp

<body style="padding: {{ $headerTopMm }}mm 10mm 65mm 10mm">

{{-- Fixed page header: company chrome + supplier/meta bands --}}
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
                            @if($supplier->company_name)
                                <div class="bill-company">{{ $supplier->company_name }}</div>
                            @endif
                            @if($supplierPerson)
                                <div class="bill-name">{{ $supplierPerson }}</div>
                            @endif
                            <div class="bill-addr">
                                @foreach($supplierAddressLines as $line)
                                    {{ $line }}<br>
                                @endforeach
                                @if($supplier->supplier_vat_number)
                                    VAT No. {{ $supplier->supplier_vat_number }}
                                @endif
                            </div>
                        </td>
                        <td class="right meta-right">
                            <div class="doc-title">Debit Note</div>
                            <table class="kv">
                                <tr><td class="k">Date</td><td class="c">:</td><td>{{ $debitNote->doc_date->format('d/m/Y') }}</td></tr>
                                <tr><td class="k">Debit Note No.</td><td class="c">:</td><td>{{ $debitNote->reference }}</td></tr>
                                <tr><td class="k">Supplier</td><td class="c">:</td><td>{{ $supplier->company_name }}</td></tr>
                                @if($againstInvoiceNo)
                                    <tr><td class="k">Against Invoice</td><td class="c">:</td><td>{{ $againstInvoiceNo }}</td></tr>
                                @endif
                                @if($supplierRefNo)
                                    <tr><td class="k">Supplier Ref.</td><td class="c">:</td><td>{{ $supplierRefNo }}</td></tr>
                                @endif
                                <tr><td class="k">Sheet No.</td><td class="c">:</td><td>&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr></table>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- Content box rails — fill the area down to the footer on every page --}}
<div class="content-frame" style="top: {{ $headerTopMm }}mm; bottom: 65mm"></div>

{{-- Standalone items table; thead repeats on every page --}}
<table class="items">
    <thead>
        <tr>
            <th class="right" style="width:9%">Qty</th>
            <th>Details</th>
            <th style="width:12%">Per</th>
            <th class="right" style="width:15%">Amount</th>
            <th class="right" style="width:15%">Net Value</th>
        </tr>
    </thead>
    <tbody>
        @foreach($debitNote->items as $item)
            @if($item->is_note)
                <tr>
                    <td class="item-cell"></td>
                    <td class="item-cell" colspan="4"><em>{{ $item->description }}</em></td>
                </tr>
                @continue
            @endif
            @if(trim((string) $item->description) === '' && (float) $item->quantity === 0.0 && (float) $item->amount === 0.0 && (float) $item->total === 0.0)
                @continue
            @endif
            <tr>
                <td class="item-cell right">{{ (float) $item->quantity !== 0.0 ? rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') : '' }}</td>
                <td class="item-cell">{{ $item->description }}</td>
                <td class="item-cell">{{ $item->per }}</td>
                <td class="item-cell right">{{ (float) $item->amount !== 0.0 ? number_format($item->amount, 2) : '' }}</td>
                <td class="item-cell right">{{ (float) $item->total !== 0.0 ? number_format($item->total, 2) : '' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- End-of-document block — glued to page bottom via position:fixed,
     paints on every page above the legal footer. --}}
<div class="bottom-block" style="bottom: 26mm; height: 38mm">
    <table class="doc-bottom">
        <tbody>
            <tr>
                <td colspan="2" class="split-cell">
                    <table class="split"><tr>
                        <td class="left cert-box">
                            @if($debitNote->notes)
                                <div class="cert-title">Notes:</div>
                                <div>{{ $debitNote->notes }}</div>
                            @else
                                &nbsp;
                            @endif
                        </td>
                        <td class="right cert-box">
                            <table class="totals-box">
                                <tr>
                                    <td class="l">Subtotal</td>
                                    <td class="v">£{{ number_format($debitNote->subtotal, 2) }}</td>
                                </tr>
                                @if((float) $debitNote->vat_amount > 0)
                                    <tr>
                                        <td class="l">VAT @ {{ rtrim(rtrim(number_format($debitNote->effectiveVatRate(), 2), '0'), '.') }}%</td>
                                        <td class="v">£{{ number_format($debitNote->vat_amount, 2) }}</td>
                                    </tr>
                                @endif
                                <tr class="grand">
                                    <td class="l">Debit Total</td>
                                    <td class="v">£{{ number_format($debitNote->total, 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr></table>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- Per-page Sheet No. drawn via inline PHP script (dompdf's only reliable
     way to substitute page number/count). Coordinates target the empty value
     cell in the supplier-meta row of the chrome. --}}
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

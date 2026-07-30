<!DOCTYPE html>
<html>

<head>
    <title>FBR Digital Invoice - {{ $invoice->invoice_number }}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />

    @include("app.pdf.partials.fonts")

    @php
        $company = $invoice->company;
        $customer = $invoice->customer;
        $currency = $customer->currency;
        $fbrSubmission = $invoice->latestFbrSubmission ?: $invoice->fbrSubmissions()->latest()->first();
        $sellerName = \App\Models\CompanySetting::getSetting('fbr_seller_business_name', $invoice->company_id) ?: $company->name;
        $sellerNtn = \App\Models\CompanySetting::getSetting('fbr_seller_ntn', $invoice->company_id) ?: $company->tax_id ?: $company->vat_id;
        $sellerAddress = \App\Models\CompanySetting::getSetting('fbr_seller_address', $invoice->company_id) ?: strip_tags((string) $company_address);
        $buyerName = $customer->company_name ?: $customer->name;
        $buyerTaxId = $customer->fbr_ntn ?: $customer->fbr_cnic ?: $customer->tax_id;
        $buyerAddress = strip_tags((string) $billing_address);
        $money = fn ($amount) => format_money_pdf((int) $amount, $currency);
        $plainMoney = fn ($amount) => number_format(((int) $amount) / 100, 2);
        $itemTax = function ($item) use ($invoice) {
            $tax = $item->taxes->first() ?: $invoice->taxes->first();

            return [
                'percent' => $tax?->percent,
                'amount' => (int) ($item->tax ?: ($tax?->amount ?? 0)),
            ];
        };
        $extraLineTax = fn ($item) => (int) ($item->fbr_further_tax ?? 0) + (int) ($item->fbr_extra_tax ?? 0) + (int) ($item->fbr_fed_payable ?? 0);
        $grandTax = (int) $invoice->tax + $invoice->items->sum(fn ($item) => $extraLineTax($item));
        $grandTotal = (int) $invoice->sub_total - (int) $invoice->discount_val + $grandTax;
        $amountWords = number_format($grandTotal / 100, 2).' Only';

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
            $amountWords = ucfirst($formatter->format((int) round($grandTotal / 100))).' Only';
        }
    @endphp

    <style type="text/css">
        @page {
            margin: 28px 32px;
        }

        body {
            color: #252b33;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.35;
            margin: 0;
        }

        .brand {
            color: #08427d;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 1px;
            text-align: center;
            text-transform: uppercase;
        }

        .top-meta {
            margin-top: 18px;
            width: 100%;
        }

        .top-meta td {
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .blue-line {
            border-top: 2px solid #0d56a6;
            margin: 8px 0 18px;
        }

        .panel-table {
            width: 100%;
        }

        .panel-table td {
            vertical-align: top;
            width: 50%;
        }

        .section-title {
            color: #0d56a6;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .info-line {
            margin-bottom: 4px;
        }

        .label {
            font-weight: 700;
        }

        .items-title {
            color: #0d56a6;
            font-size: 16px;
            font-weight: 700;
            margin: 22px 0 8px;
        }

        .items {
            border-collapse: collapse;
            width: 100%;
        }

        .items th {
            background: #0d56a6;
            border: 1px solid #0d56a6;
            color: #ffffff;
            font-size: 9px;
            padding: 6px 4px;
            text-align: center;
        }

        .items td {
            border: 1px solid #d7dce3;
            font-size: 9px;
            padding: 6px 4px;
            vertical-align: top;
        }

        .items .num {
            text-align: right;
            white-space: nowrap;
        }

        .totals-wrap {
            margin-top: 20px;
            width: 100%;
        }

        .totals {
            border-collapse: collapse;
            float: right;
            width: 48%;
        }

        .totals td {
            border: 1px solid #d7dce3;
            padding: 7px 9px;
        }

        .totals .total-row td {
            color: #0d56a6;
            font-size: 13px;
            font-weight: 700;
        }

        .watermark {
            color: #0d56a6;
            font-size: 66px;
            font-weight: 700;
            left: 150px;
            opacity: 0.06;
            position: fixed;
            top: 385px;
            transform: rotate(-28deg);
            z-index: -1;
        }

        .signature {
            clear: both;
            padding-top: 70px;
            width: 48%;
        }

        .signature-line {
            border-top: 1px solid #252b33;
            padding-top: 7px;
            text-align: center;
        }

        .words {
            margin-top: 20px;
        }

        .verification {
            margin-top: 22px;
            text-align: center;
        }

        .fbr-badge {
            border: 2px solid #2ea44f;
            border-radius: 8px;
            color: #2d3748;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 8px 14px;
        }

        .qr {
            border: 2px solid #252b33;
            display: inline-block;
            font-size: 9px;
            height: 76px;
            margin-left: 14px;
            padding-top: 24px;
            text-align: center;
            vertical-align: middle;
            width: 76px;
        }

        .footer {
            border-top: 2px solid #0d56a6;
            bottom: 0;
            color: #0d56a6;
            font-size: 10px;
            left: 0;
            padding-top: 6px;
            position: fixed;
            text-align: center;
            width: 100%;
        }
    </style>
</head>

<body>
    <div class="watermark">{{ $sellerName }}</div>

    <div class="brand">{{ $sellerName }}</div>

    <table class="top-meta">
        <tr>
            <td><span class="label">Sales Invoice No:</span> {{ $invoice->invoice_number }}</td>
            <td class="right">
                <div><span class="label">Date:</span> {{ $invoice->invoice_date->format('d/m/Y') }}</div>
                <div><span class="label">Created At:</span> {{ $invoice->created_at->format('l, F d, Y / h:i A') }}</div>
            </td>
        </tr>
    </table>

    <div class="blue-line"></div>

    <table class="panel-table">
        <tr>
            <td>
                <div class="section-title">Bill To</div>
                <div class="info-line"><span class="label">Name:</span> {{ $buyerName ?: 'N/A' }}</div>
                <div class="info-line"><span class="label">NTN/CNIC:</span> {{ $buyerTaxId ?: 'N/A' }}</div>
                <div class="info-line"><span class="label">Mobile:</span> {{ $customer->phone ?: 'N/A' }}</div>
                <div class="info-line"><span class="label">Address:</span> {{ $buyerAddress ?: 'N/A' }}</div>
                <div class="info-line"><span class="label">PO#:</span> {{ $invoice->reference_number ?: 'N/A' }}</div>
            </td>
            <td class="right">
                <div class="section-title">Seller Info</div>
                <div class="info-line"><span class="label">FBR Invoice No:</span> {{ $fbrSubmission?->fbr_invoice_number ?: 'Pending' }}</div>
                <div class="info-line"><span class="label">Seller NTN #:</span> {{ $sellerNtn ?: 'N/A' }}</div>
                <div class="info-line"><span class="label">Seller Address:</span> {{ $sellerAddress ?: 'N/A' }}</div>
            </td>
        </tr>
    </table>

    <div class="items-title">Items</div>

    <table class="items">
        <thead>
            <tr>
                <th width="4%">Sr</th>
                <th width="18%">Name</th>
                <th width="10%">Code</th>
                <th width="9%">Price</th>
                <th width="7%">Qty</th>
                <th width="8%">UOM</th>
                <th width="7%">Tax %</th>
                <th width="8%">Ext Tax</th>
                <th width="7%">FED</th>
                <th width="8%">Held Tax</th>
                <th width="8%">Tax Charged</th>
                <th width="10%">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                @php
                    $tax = $itemTax($item);
                    $lineExtraTax = $extraLineTax($item);
                    $lineTotal = (int) $item->total + (int) $tax['amount'] + $lineExtraTax;
                @endphp
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>
                        {{ $item->name }}
                        @if ($item->description)
                            <br><span>{{ $item->description }}</span>
                        @endif
                    </td>
                    <td>{{ $item->fbr_hs_code ?: $item->item?->fbr_hs_code ?: 'Missing' }}</td>
                    <td class="num">{!! $money($item->price) !!}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                    <td>{{ $item->fbr_uom ?: $item->unit_name ?: $item->item?->fbr_uom ?: 'Missing' }}</td>
                    <td class="num">{{ $tax['percent'] !== null ? rtrim(rtrim(number_format($tax['percent'], 2), '0'), '.').'%' : 'Missing' }}</td>
                    <td class="num">{!! $money((int) ($item->fbr_extra_tax ?? 0) + (int) ($item->fbr_further_tax ?? 0)) !!}</td>
                    <td class="num">{!! $money($item->fbr_fed_payable ?? 0) !!}</td>
                    <td class="num">{!! $money($item->fbr_sales_tax_withheld ?? 0) !!}</td>
                    <td class="num">{!! $money($tax['amount']) !!}</td>
                    <td class="num">{!! $money($lineTotal) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals">
            <tr>
                <td>Total Qty</td>
                <td class="right">{{ rtrim(rtrim(number_format($invoice->items->sum('quantity'), 2), '0'), '.') }}</td>
            </tr>
            <tr>
                <td>Subtotal (Excluding Tax)</td>
                <td class="right">{!! $money($invoice->sub_total - $invoice->discount_val) !!}</td>
            </tr>
            <tr>
                <td>Standard Tax</td>
                <td class="right">{!! $money($invoice->tax) !!}</td>
            </tr>
            <tr>
                <td>Grand Total Tax</td>
                <td class="right">{!! $money($grandTax) !!}</td>
            </tr>
            <tr class="total-row">
                <td>Grand Total (Bill Amount)</td>
                <td class="right">{!! $money($grandTotal) !!}</td>
            </tr>
        </table>
    </div>

    <div class="signature">
        <div class="signature-line">Signature & Stamp</div>
    </div>

    <div class="words"><span class="label">Amount in Words:</span> {{ $amountWords }}</div>

    <div class="verification">
        <span class="fbr-badge">FBR DIGITAL INVOICING SYSTEM</span>
        <span class="qr">QR CODE<br>{{ $fbrSubmission?->fbr_invoice_number ?: $invoice->invoice_number }}</span>
        <div style="margin-top: 8px;">
            For Verification: Scan QR Code. Invoice No:
            {{ $fbrSubmission?->fbr_invoice_number ?: $invoice->invoice_number }}
        </div>
    </div>

    <div class="footer">
        FBR compliant digital invoicing powered by {{ $sellerName }}
    </div>
</body>

</html>

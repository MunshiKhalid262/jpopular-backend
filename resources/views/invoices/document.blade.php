{{--
    The invoice document, in the bordered Indian tax-invoice layout.

    ONE template renders the PDF, the on-screen preview and the print view, so
    the three can never drift apart. `$media` switches only presentation:
      pdf    - dompdf, which supports a limited CSS subset (no flex/grid)
      screen - browser preview, wrapped in a page-like frame
      print  - browser print, with @media print hiding the host page's chrome

    Layout is TABLE-BASED throughout, and the cell borders ARE the design.
    dompdf has no flexbox or grid, so a flex layout that looked right in the
    preview would collapse in the PDF.

    Dealer invoices carry the transport block and a second e-Way Bill page; a
    customer invoice carries neither, because a counter sale has no driver to
    produce one.
--}}
@php
    use App\Support\AmountInWords;
    use App\Support\DecimalFormat as F;

    $media ??= 'pdf';

    $gst = $doc->chargesGst();
    $interState = $doc->isInterState();
    $dealer = $doc->isDealer();
    $showHsn = $gst && $doc->hasHsnCodes();
    $showInclusive = $doc->showsInclusiveRate();
    $summary = $doc->taxSummary();
    $summaryTotals = $doc->taxSummaryTotals();
    $charges = $doc->charges();

    // Sl, Description, [HSN], Qty, [Rate incl], Rate, per, Disc, Amount
    $columns = 6 + ($showHsn ? 1 : 0) + ($showInclusive ? 1 : 0) + 1;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $doc->heading() }} {{ $invoice->invoice_number ?? '' }}</title>
    <style>
        @page { margin: 8mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5px;
            line-height: 1.3;
            color: #000;
            margin: 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        .b   { border: 0.6px solid #000; }
        .bt  { border-top: 0.6px solid #000; }
        .bb  { border-bottom: 0.6px solid #000; }
        .bl  { border-left: 0.6px solid #000; }
        .br  { border-right: 0.6px solid #000; }

        .p3 { padding: 3px 4px; }
        .p2 { padding: 2px 4px; }

        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .ital { font-style: italic; }
        .small { font-size: 7.5px; }
        .lbl { font-size: 7px; color: #333; }

        .doc-title { font-size: 13px; font-weight: bold; letter-spacing: 0.04em; }

        .items th {
            border: 0.6px solid #000;
            padding: 3px 4px;
            font-size: 7.5px;
            font-weight: bold;
            text-align: center;
        }
        .items td { padding: 2px 4px; }

        /* The items body is one tall block so the table keeps its shape even
           with two lines on it, exactly as a printed invoice book does. */
        .items .filler { height: 150px; }

        .sum th, .sum td {
            border: 0.6px solid #000;
            padding: 2px 4px;
            font-size: 7.5px;
        }
        .sum th { text-align: center; font-weight: bold; }

        .watermark {
            position: fixed;
            top: 40%;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 80px;
            font-weight: bold;
            letter-spacing: 0.1em;
            color: #b42318;
            opacity: 0.12;
            transform: rotate(-22deg);
        }
        .cancelled-badge {
            display: inline-block;
            border: 1.5px solid #b42318;
            color: #b42318;
            font-weight: bold;
            letter-spacing: 0.1em;
            padding: 1px 8px;
        }

        .page-break { page-break-before: always; }

        @if ($media === 'screen')
            body { background: #f4f4f5; padding: 20px 0; }
            .sheet {
                width: 200mm;
                margin: 0 auto 18px;
                background: #fff;
                padding: 8mm;
                box-shadow: 0 1px 3px rgba(0,0,0,.12), 0 8px 24px rgba(0,0,0,.08);
            }
        @endif

        @if ($media !== 'pdf')
            /* Only the invoice prints: the host page hides its own chrome with
               this same rule set. */
            @media print {
                body { background: #fff; padding: 0; }
                .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
                .no-print { display: none !important; }
                .page-break { page-break-before: always; }
            }
        @endif
    </style>
</head>
<body>

@if ($doc->isCancelled())
    <div class="watermark">CANCELLED</div>
@endif

<div class="{{ $media === 'screen' ? 'sheet' : '' }}">

    {{-- ------------------------------------------------------ title row --}}
    <table>
        <tr>
            <td style="width:25%"></td>
            <td class="center doc-title">{{ $doc->heading() }}</td>
            <td class="right bold" style="width:25%">
                @if ($invoice->hasEInvoiceDetails()) e-Invoice @endif
            </td>
        </tr>
    </table>

    {{-- e-Invoice block. Entered by hand from the government portal; there is
         no IRP integration, so it prints only when it has been filled in. --}}
    @if ($invoice->hasEInvoiceDetails())
        <table style="margin:4px 0 6px">
            <tr>
                <td style="width:52px" class="lbl">IRN</td>
                <td class="bold small" style="word-break:break-all">: {{ $invoice->irn }}</td>
            </tr>
            @if ($invoice->ack_no)
                <tr>
                    <td class="lbl">Ack No.</td>
                    <td class="bold small">: {{ $invoice->ack_no }}</td>
                </tr>
            @endif
            @if ($invoice->ack_date)
                <tr>
                    <td class="lbl">Ack Date</td>
                    <td class="bold small">: {{ $invoice->ack_date->format('j-M-y') }}</td>
                </tr>
            @endif
        </table>
    @endif

    {{-- ------------------------------------------- parties and document --}}
    <table class="b">
        <tr>
            {{-- Left: seller, consignee, buyer --}}
            <td class="br" style="width:52%">
                <div class="p3 bb">
                    <div class="bold">{{ $seller->business_name }}</div>
                    @if ($seller->legal_name && $seller->legal_name !== $seller->business_name)
                        <div>{{ $seller->legal_name }}</div>
                    @endif
                    @foreach (array_filter([$seller->address_line1, $seller->address_line2]) as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    <div>{{ collect([$seller->city, $seller->state, $seller->pincode])->filter()->implode(', ') }}</div>
                    @if ($gst && $seller->gstin)
                        <div>GSTIN/UIN: {{ $seller->gstin }}</div>
                    @endif
                    @if ($seller->state && $seller->state_code)
                        <div>State Name : {{ $seller->state }}, Code : {{ $seller->state_code }}</div>
                    @endif
                    @if ($seller->email)
                        <div>E-Mail : {{ $seller->email }}</div>
                    @endif
                </div>

                @php
                    /*
                     * The consignee IS the buyer: J Popular delivers to the
                     * dealer who bought the goods. Both blocks are built from
                     * this one list, so the document cannot contradict itself
                     * about who the party is.
                     */
                    $party = $invoice->customer;
                    $partyLines = [];

                    if ($party) {
                        if (filled($party->address)) {
                            $partyLines[] = $party->address;
                        }

                        $place = collect([$party->city, $party->state, $party->pincode])
                            ->filter()->implode(', ');

                        if (filled($place)) {
                            $partyLines[] = $place;
                        }

                        if (filled($party->phone)) {
                            $partyLines[] = 'Phone : '.$party->phone;
                        }

                        if ($gst && filled($party->gstin)) {
                            $partyLines[] = 'GSTIN/UIN : '.$party->gstin;
                        }

                        if (filled($party->state) && filled($party->state_code)) {
                            $partyLines[] = 'State Name : '.$party->state.', Code : '.$party->state_code;
                        } elseif (filled($party->state_code)) {
                            $partyLines[] = 'State Code : '.$party->state_code;
                        }
                    }
                @endphp

                {{-- Ship to, shown only on a dealer invoice. A counter bill is
                     handed over with the goods, so it carries no consignee. --}}
                @if ($dealer)
                    <div class="p3 bb">
                        <div class="lbl">Consignee (Ship to)</div>
                        <div class="bold">{{ $party?->name ?? 'Walk-in customer' }}</div>
                        @foreach ($partyLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="p3">
                    <div class="lbl">{{ $dealer ? 'Buyer (Bill to)' : 'Billed to' }}</div>
                    <div class="bold">{{ $party?->name ?? 'Walk-in customer' }}</div>
                    @foreach ($partyLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>

            {{-- Right: the document reference grid --}}
            <td>
                @php
                    // Only the boxes that apply. A retail bill has no dispatch
                    // details, and printing empty cells would be noise.
                    $boxes = [
                        ['Invoice No.', $invoice->invoice_number ?? 'DRAFT', 'Dated', optional($invoice->invoice_date)->format('j-M-y')],
                    ];

                    if ($dealer) {
                        $boxes[0][1] = $invoice->invoice_number ?? 'DRAFT';
                        $boxes[] = ['e-Way Bill No.', $invoice->eway_bill_no, 'Mode/Terms of Payment', $invoice->mode_of_payment];
                        $boxes[] = ['Delivery Note', $invoice->delivery_note, 'Delivery Note Date', optional($invoice->delivery_note_date)->format('j-M-y')];
                        $boxes[] = ['Buyer\'s Order No.', $invoice->buyer_order_no, 'Dated', optional($invoice->buyer_order_date)->format('j-M-y')];
                        $boxes[] = ['Dispatch Doc No.', $invoice->dispatch_doc_no, 'Other References', $invoice->other_references];
                        $boxes[] = ['Dispatched through', $invoice->dispatched_through, 'Destination', $invoice->destination];
                        $boxes[] = ['Bill of Lading/LR-RR No.', trim(($invoice->lr_rr_no ?? '').' '.(optional($invoice->lr_rr_date)->format('j-M-y') ? 'dt. '.$invoice->lr_rr_date->format('j-M-y') : '')), 'Motor Vehicle No.', $invoice->vehicle_no];
                        $boxes[] = ['Terms of Delivery', $invoice->terms_of_delivery, null, null];
                    } else {
                        $boxes[] = ['Mode/Terms of Payment', $invoice->mode_of_payment, 'Invoice Type', 'Customer'];
                        if ($gst && $invoice->place_of_supply_state_code) {
                            $boxes[] = ['Place of Supply', $invoice->place_of_supply_state_code, 'Supply', $interState ? 'Inter-state' : 'Intra-state'];
                        }
                    }
                @endphp

                <table>
                    @foreach ($boxes as $row)
                        <tr>
                            <td class="p2 bb {{ $row[2] !== null ? 'br' : '' }}" style="width:50%">
                                <div class="lbl">{{ $row[0] }}</div>
                                <div class="bold">{{ filled($row[1]) ? $row[1] : ' ' }}</div>
                            </td>
                            @if ($row[2] !== null)
                                <td class="p2 bb">
                                    <div class="lbl">{{ $row[2] }}</div>
                                    <div class="bold">{{ filled($row[3]) ? $row[3] : ' ' }}</div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </table>

                @if ($doc->isCancelled())
                    <div class="p3 center">
                        <span class="cancelled-badge">CANCELLED</span>
                        @if ($invoice->cancellation_reason)
                            <div class="small" style="color:#b42318">{{ $invoice->cancellation_reason }}</div>
                        @endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ------------------------------------------------------------ items --}}
    <table class="items" style="margin-top:-0.6px">
        <thead>
        <tr>
            <th style="width:22px">Sl<br>No.</th>
            <th>Description of<br>Goods and Services</th>
            @if ($showHsn)<th style="width:58px">HSN/SAC</th>@endif
            <th style="width:58px">Quantity</th>
            @if ($showInclusive)<th style="width:60px">Rate<br>(Incl. of Tax)</th>@endif
            <th style="width:60px">Rate</th>
            <th style="width:28px">per</th>
            <th style="width:38px">Disc. %</th>
            <th style="width:76px">Amount</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($doc->lines() as $i => $item)
            <tr>
                <td class="bl br center">{{ $i + 1 }}</td>
                {{-- Snapshot values: never the live product. --}}
                <td class="br bold">{{ $item->product_name }}<div class="small" style="font-weight:normal">{{ $item->sku }}</div></td>
                @if ($showHsn)<td class="br center">{{ $item->hsn_code ?? '—' }}</td>@endif
                <td class="br right bold">{{ F::quantity((string) $item->quantity) }} {{ $item->unit }}</td>
                @if ($showInclusive)
                    <td class="br right">{{ F::amount((string) $item->unit_price_gross) }}</td>
                @endif
                <td class="br right">{{ F::amount((string) $item->unit_price) }}</td>
                <td class="br center">{{ $item->unit }}</td>
                <td class="br right">
                    {{ bccomp((string) $item->discount_amount, '0', 2) > 0 ? F::amount((string) $item->discount_amount) : '' }}
                </td>
                <td class="br right bold">{{ F::amount((string) $item->line_total) }}</td>
            </tr>
        @empty
            <tr>
                <td class="bl br center" colspan="{{ $columns }}" style="padding:12px">This invoice has no lines.</td>
            </tr>
        @endforelse

        {{-- Goods subtotal, as the sample shows it above the charges. --}}
        @if ($doc->lines()->isNotEmpty())
            <tr>
                <td class="bl br"></td>
                <td class="br" colspan="{{ $columns - 3 }}"></td>
                <td class="br"></td>
                <td class="br right bold">{{ F::amount((string) $invoice->subtotal) }}</td>
            </tr>
        @endif

        {{-- Additional charges: insurance, freight. Each at its own rate. --}}
        @foreach ($charges as $charge)
            <tr>
                <td class="bl br"></td>
                <td class="br right ital bold" colspan="{{ $showHsn ? 1 : 1 }}">{{ $charge->description }}</td>
                @if ($showHsn)<td class="br center">{{ $charge->hsn_code ?? '' }}</td>@endif
                <td class="br"></td>
                @if ($showInclusive)<td class="br"></td>@endif
                <td class="br right">{{ bccomp((string) $charge->gst_rate, '0', 2) > 0 ? F::rate((string) $charge->gst_rate).' %' : '' }}</td>
                <td class="br"></td>
                <td class="br"></td>
                <td class="br right bold">{{ F::amount((string) $charge->taxable_amount) }}</td>
            </tr>
        @endforeach

        {{-- Tax lines --}}
        @if ($gst)
            @if ($interState)
                <tr>
                    <td class="bl br"></td>
                    <td class="br right ital bold" colspan="{{ $columns - 3 }}">Output IGST</td>
                    <td class="br"></td>
                    <td class="br right bold">{{ F::amount((string) $invoice->igst_amount) }}</td>
                </tr>
            @else
                <tr>
                    <td class="bl br"></td>
                    <td class="br right ital bold" colspan="{{ $columns - 3 }}">Output CGST</td>
                    <td class="br"></td>
                    <td class="br right bold">{{ F::amount((string) $invoice->cgst_amount) }}</td>
                </tr>
                <tr>
                    <td class="bl br"></td>
                    <td class="br right ital bold" colspan="{{ $columns - 3 }}">Output SGST</td>
                    <td class="br"></td>
                    <td class="br right bold">{{ F::amount((string) $invoice->sgst_amount) }}</td>
                </tr>
            @endif
        @endif

        @if (bccomp((string) $invoice->discount_amount, '0', 2) > 0)
            <tr>
                <td class="bl br"></td>
                <td class="br right ital" colspan="{{ $columns - 3 }}">Less : Discount</td>
                <td class="br"></td>
                <td class="br right bold">(-){{ F::amount((string) $invoice->discount_amount) }}</td>
            </tr>
        @endif

        @if (bccomp((string) $invoice->round_off, '0', 2) !== 0)
            <tr>
                <td class="bl br"></td>
                <td class="br right ital" colspan="{{ $columns - 3 }}">Less : Round Off</td>
                <td class="br"></td>
                <td class="br right bold">
                    {{ bccomp((string) $invoice->round_off, '0', 2) < 0 ? '(-)' : '' }}{{ F::amount(ltrim((string) $invoice->round_off, '-')) }}
                </td>
            </tr>
        @endif

        {{-- Keeps the table a consistent height, like a printed invoice book. --}}
        <tr class="filler">
            <td class="bl br"></td>
            <td class="br" colspan="{{ $columns - 3 }}"></td>
            <td class="br"></td>
            <td class="br"></td>
        </tr>
        </tbody>

        <tfoot>
        <tr>
            <td class="b"></td>
            <td class="b right bold" colspan="{{ $showHsn ? 2 : 1 }}">Total</td>
            <td class="b right bold">{{ F::quantity($doc->totalQuantity()) }} {{ $doc->commonUnit() }}</td>
            @if ($showInclusive)<td class="b"></td>@endif
            <td class="b"></td>
            <td class="b"></td>
            <td class="b"></td>
            <td class="b right bold" style="font-size:10px">&#8377; {{ F::amount((string) $invoice->grand_total) }}</td>
        </tr>
        </tfoot>
    </table>

    {{-- --------------------------------------------- amount in words --}}
    <table class="b" style="margin-top:-0.6px">
        <tr>
            <td class="p3">
                <span class="lbl">Amount Chargeable (in words)</span>
                <div class="bold">{{ AmountInWords::rupees((string) $invoice->grand_total) }}</div>
            </td>
            <td class="p3 right ital" style="width:70px">E. &amp; O.E</td>
        </tr>
    </table>

    {{-- Money recap.
         The reference layout omits this, because a supplier's dealer invoice
         is not a receipt. Ours keeps it: a shop's own bill has to tell the
         customer what has been paid and what is still due. --}}
    <table class="b" style="margin-top:-0.6px">
        <tr>
            <td class="p2 br"><span class="lbl">Subtotal</span><div class="bold">{{ F::amount((string) $invoice->subtotal) }}</div></td>
            @if (bccomp((string) $invoice->discount_amount, '0', 2) > 0)
                <td class="p2 br"><span class="lbl">Discount</span><div class="bold">{{ F::amount((string) $invoice->discount_amount) }}</div></td>
            @endif
            <td class="p2 br"><span class="lbl">Grand total</span><div class="bold">{{ F::amount((string) $invoice->grand_total) }}</div></td>
            <td class="p2 br"><span class="lbl">Paid</span><div class="bold">{{ F::amount((string) $invoice->paid_amount) }}</div></td>
            <td class="p2"><span class="lbl">Balance due</span><div class="bold">{{ F::amount($doc->balanceDue()) }}</div></td>
        </tr>
    </table>

    {{-- ------------------------------------------------- HSN tax summary --}}
    @if ($gst && $summary !== [])
        <table class="sum" style="margin-top:4px">
            <thead>
            <tr>
                <th rowspan="2" style="width:80px">HSN/SAC</th>
                <th rowspan="2" style="width:80px">Taxable<br>Value</th>
                @if ($interState)
                    <th colspan="2">IGST</th>
                @else
                    <th colspan="2">CGST</th>
                    <th colspan="2">SGST/UTGST</th>
                @endif
                <th rowspan="2" style="width:80px">Total<br>Tax Amount</th>
            </tr>
            <tr>
                @if ($interState)
                    <th style="width:44px">Rate</th>
                    <th style="width:70px">Amount</th>
                @else
                    <th style="width:44px">Rate</th>
                    <th style="width:70px">Amount</th>
                    <th style="width:44px">Rate</th>
                    <th style="width:70px">Amount</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($summary as $row)
                <tr>
                    <td>{{ $row['hsn_code'] ?? '—' }}</td>
                    <td class="right">{{ F::amount($row['taxable']) }}</td>
                    @if ($interState)
                        <td class="center">{{ F::rate($row['igst_rate']) }}%</td>
                        <td class="right">{{ F::amount($row['igst']) }}</td>
                    @else
                        <td class="center">{{ F::rate($row['cgst_rate']) }}%</td>
                        <td class="right">{{ F::amount($row['cgst']) }}</td>
                        <td class="center">{{ F::rate($row['sgst_rate']) }}%</td>
                        <td class="right">{{ F::amount($row['sgst']) }}</td>
                    @endif
                    <td class="right">{{ F::amount($row['tax']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td class="right bold">Total</td>
                <td class="right bold">{{ F::amount($summaryTotals['taxable']) }}</td>
                @if ($interState)
                    <td></td>
                    <td class="right bold">{{ F::amount($summaryTotals['igst']) }}</td>
                @else
                    <td></td>
                    <td class="right bold">{{ F::amount($summaryTotals['cgst']) }}</td>
                    <td></td>
                    <td class="right bold">{{ F::amount($summaryTotals['sgst']) }}</td>
                @endif
                <td class="right bold">{{ F::amount($summaryTotals['tax']) }}</td>
            </tr>
            </tbody>
        </table>

        <table class="b" style="margin-top:-0.6px">
            <tr>
                <td class="p3">
                    <span class="lbl">Tax Amount (in words) :</span>
                    <span class="bold">{{ AmountInWords::tax($summaryTotals['tax']) }}</span>
                </td>
            </tr>
        </table>
    @endif

    {{-- ------------------------------------------ declaration + signatory --}}
    <table class="b" style="margin-top:4px">
        <tr>
            <td class="p3 br" style="width:58%">
                <div class="lbl" style="text-decoration:underline">Declaration</div>
                <div>
                    {{ $seller->invoice_declaration
                        ?: 'We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.' }}
                </div>
                @if ($invoice->terms ?? $seller->invoice_terms)
                    <div style="margin-top:4px">{{ $invoice->terms ?? $seller->invoice_terms }}</div>
                @endif
                @if ($seller->bank_name || $seller->upi_id)
                    <div style="margin-top:4px">
                        <span class="bold">Bank :</span>
                        {{ collect([$seller->bank_name, $seller->bank_account_number, $seller->bank_ifsc, $seller->upi_id])->filter()->implode(' · ') }}
                    </div>
                @endif
            </td>
            <td class="p3 right">
                <div class="bold">for {{ $seller->business_name }}</div>
                <div style="height:44px"></div>
                <div>Authorised Signatory</div>
            </td>
        </tr>
    </table>

    <div class="center small" style="margin-top:4px">This is a Computer Generated Invoice</div>
</div>

{{-- ============================================================ e-Way Bill
     Dealer invoices only, and only once the number has been entered. A
     customer bill never carries one, and a dealer invoice without an e-Way
     Bill yet should not print a blank page. --}}
@if ($dealer && filled($invoice->eway_bill_no))
    <div class="page-break {{ $media === 'screen' ? 'sheet' : '' }}">
        <table>
            <tr>
                <td style="width:25%"></td>
                <td class="center doc-title">e-Way Bill</td>
                <td class="right bold" style="width:25%">e-Way Bill</td>
            </tr>
        </table>

        <table style="margin:4px 0 6px">
            <tr>
                <td style="width:60px" class="lbl">Doc No.</td>
                <td class="bold">: {{ $doc->heading() }} - {{ $invoice->invoice_number }}</td>
            </tr>
            <tr>
                <td class="lbl">Date</td>
                <td class="bold">: {{ optional($invoice->invoice_date)->format('j-M-y') }}</td>
            </tr>
            @if ($invoice->irn)
                <tr>
                    <td class="lbl">IRN</td>
                    <td class="bold small" style="word-break:break-all">: {{ $invoice->irn }}</td>
                </tr>
            @endif
        </table>

        <table class="b">
            <tr><td class="p3 bb bold" colspan="4">1. e-Way Bill Details</td></tr>
            <tr>
                <td class="p2 br" style="width:25%"><span class="lbl">e-Way Bill No.</span><div class="bold">{{ $invoice->eway_bill_no }}</div></td>
                <td class="p2 br" style="width:25%"><span class="lbl">Mode</span><div class="bold">{{ $invoice->dispatched_through ?: '—' }}</div></td>
                <td class="p2 br" style="width:25%"><span class="lbl">Document Date</span><div class="bold">{{ optional($invoice->invoice_date)->format('j-M-y') }}</div></td>
                <td class="p2"><span class="lbl">Destination</span><div class="bold">{{ $invoice->destination ?: '—' }}</div></td>
            </tr>

            <tr><td class="p3 bt bb bold" colspan="4">2. Address Details</td></tr>
            <tr>
                <td class="p3 br" colspan="2">
                    <div class="bold">From</div>
                    <div>{{ $seller->business_name }}</div>
                    @if ($seller->gstin)<div>GSTIN : {{ $seller->gstin }}</div>@endif
                    <div>{{ collect([$seller->address_line1, $seller->city, $seller->state, $seller->pincode])->filter()->implode(', ') }}</div>
                </td>
                <td class="p3" colspan="2">
                    {{-- The same party as Bill to and Ship to on page one. --}}
                    <div class="bold">To</div>
                    <div>{{ $invoice->customer?->name }}</div>
                    @if ($gst && $invoice->customer?->gstin)
                        <div>GSTIN : {{ $invoice->customer->gstin }}</div>
                    @endif
                    <div>{{ collect([
                        $invoice->customer?->address,
                        $invoice->customer?->city,
                        $invoice->customer?->state,
                        $invoice->customer?->pincode,
                    ])->filter()->implode(', ') }}</div>
                </td>
            </tr>

            <tr><td class="p3 bt bb bold" colspan="4">3. Goods Details</td></tr>
            <tr>
                <td colspan="4" style="padding:0">
                    <table class="sum">
                        <thead>
                        <tr>
                            <th style="width:70px">HSN Code</th>
                            <th>Product Name &amp; Desc</th>
                            <th style="width:70px">Quantity</th>
                            <th style="width:90px">Taxable Amt</th>
                            <th style="width:60px">Tax Rate</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($doc->lines() as $item)
                            <tr>
                                <td>{{ $item->hsn_code ?? '—' }}</td>
                                <td>{{ $item->product_name }}</td>
                                <td class="right">{{ F::quantity((string) $item->quantity) }} {{ $item->unit }}</td>
                                <td class="right">{{ F::amount((string) $item->taxable_amount) }}</td>
                                <td class="center">
                                    @if ($interState)
                                        {{ F::rate((string) $item->igst_rate) }}
                                    @else
                                        {{ F::rate((string) $item->cgst_rate) }}+{{ F::rate((string) $item->sgst_rate) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>

            <tr>
                <td class="p2 bt br"><span class="lbl">Tot. Taxable Amt</span><div class="bold">{{ F::amount((string) $invoice->taxable_amount) }}</div></td>
                <td class="p2 bt br"><span class="lbl">Total Inv Amt</span><div class="bold">{{ F::amount((string) $invoice->grand_total) }}</div></td>
                <td class="p2 bt br">
                    <span class="lbl">{{ $interState ? 'IGST Amt' : 'CGST Amt' }}</span>
                    <div class="bold">{{ F::amount((string) ($interState ? $invoice->igst_amount : $invoice->cgst_amount)) }}</div>
                </td>
                <td class="p2 bt">
                    <span class="lbl">{{ $interState ? '—' : 'SGST Amt' }}</span>
                    <div class="bold">{{ $interState ? '' : F::amount((string) $invoice->sgst_amount) }}</div>
                </td>
            </tr>

            <tr><td class="p3 bt bb bold" colspan="4">4. Transportation &amp; Vehicle Details</td></tr>
            <tr>
                <td class="p2 br"><span class="lbl">Transporter</span><div class="bold">{{ $invoice->dispatched_through ?: '—' }}</div></td>
                <td class="p2 br"><span class="lbl">Doc No.</span><div class="bold">{{ $invoice->lr_rr_no ?: '—' }}</div></td>
                <td class="p2 br"><span class="lbl">Vehicle No.</span><div class="bold">{{ $invoice->vehicle_no ?: '—' }}</div></td>
                <td class="p2"><span class="lbl">Date</span><div class="bold">{{ optional($invoice->lr_rr_date ?? $invoice->invoice_date)->format('j-M-y') }}</div></td>
            </tr>
        </table>

        <div class="center small" style="margin-top:4px">
            e-Way Bill details as entered from the government portal.
        </div>
    </div>
@endif

</body>
</html>

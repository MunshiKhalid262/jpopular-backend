{{--
    The invoice document.

    ONE template renders the PDF, the on-screen preview and the print view, so
    the three can never drift apart. `$media` switches only presentation:
      pdf    - dompdf, which supports a limited CSS subset (no flex/grid)
      screen - browser preview, wrapped in a page-like frame
      print  - browser print, with @media print hiding everything else

    Layout is TABLE-BASED on purpose. dompdf has no flexbox or grid support, so
    a flex layout that looked right in the preview would collapse in the PDF.
--}}
@php
    use App\Support\DecimalFormat as F;

    $media ??= 'pdf';
    $gst = $doc->chargesGst();
    $interState = $doc->isInterState();
    $showHsn = $gst && $doc->hasHsnCodes();
    $taxSummary = $doc->taxSummary();

    // GST splits into two columns intra-state (CGST + SGST) and one
    // inter-state (IGST). Non-GST shows neither.
    $taxColumns = $gst ? ($interState ? 1 : 2) : 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $doc->heading() }} {{ $invoice->invoice_number ?? '' }}</title>
    <style>
        @page { margin: 14mm 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.45;
            color: #1a1a1a;
            margin: 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        .muted { color: #666; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .num { font-size: 10px; }

        .doc-title {
            font-size: 17px;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .seller-name { font-size: 14px; font-weight: bold; }

        .head { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 10px; }

        .party {
            border: 1px solid #d4d4d4;
            padding: 7px 9px;
            width: 50%;
        }
        .party-label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #666;
            margin-bottom: 3px;
        }

        .lines th {
            background: #f2f2f2;
            border: 1px solid #c8c8c8;
            padding: 5px 6px;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            text-align: left;
        }
        .lines td { border: 1px solid #d8d8d8; padding: 5px 6px; }
        .lines tbody tr:nth-child(even) td { background: #fafafa; }

        .totals td { padding: 3px 6px; }
        .totals .label { color: #555; }
        .totals .grand td {
            border-top: 1.5px solid #1a1a1a;
            font-size: 12px;
            font-weight: bold;
            padding-top: 6px;
        }

        .summary th, .summary td {
            border: 1px solid #d8d8d8;
            padding: 4px 6px;
            font-size: 9px;
        }
        .summary th { background: #f2f2f2; text-align: left; }

        .foot { margin-top: 14px; font-size: 9px; color: #555; }
        .sign { margin-top: 26px; padding-top: 4px; border-top: 1px solid #b0b0b0; width: 170px; }

        /* Cancelled is a fact about the document, so it is stated in the header
           AND stamped across the page -- a printout that loses the header must
           still be unusable as a valid invoice. */
        .cancelled-badge {
            display: inline-block;
            border: 2px solid #b42318;
            color: #b42318;
            font-weight: bold;
            letter-spacing: 0.12em;
            padding: 2px 10px;
            font-size: 12px;
        }
        .watermark {
            position: fixed;
            top: 42%;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 82px;
            font-weight: bold;
            letter-spacing: 0.1em;
            color: #b42318;
            opacity: 0.13;
            transform: rotate(-24deg);
            z-index: 0;
        }

        @if ($media === 'screen')
            body { background: #f4f4f5; padding: 24px 0; }
            .sheet {
                width: 190mm;
                min-height: 270mm;
                margin: 0 auto;
                background: #fff;
                padding: 14mm 12mm;
                box-shadow: 0 1px 3px rgba(0,0,0,.12), 0 8px 24px rgba(0,0,0,.08);
            }
        @endif

        @if ($media !== 'pdf')
            /* Only the invoice prints: the host page hides its own chrome via
               this same rule set. */
            @media print {
                body { background: #fff; padding: 0; }
                .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
                .no-print { display: none !important; }
            }
        @endif
    </style>
</head>
<body>
@if ($doc->isCancelled())
    <div class="watermark">CANCELLED</div>
@endif

<div class="{{ $media === 'screen' ? 'sheet' : '' }}">

    {{-- ------------------------------------------------------------ header --}}
    <table class="head">
        <tr>
            <td style="width:58%">
                <div class="seller-name">{{ $seller->business_name }}</div>
                @if ($seller->legal_name && $seller->legal_name !== $seller->business_name)
                    <div class="muted">{{ $seller->legal_name }}</div>
                @endif
                <div class="muted">
                    @foreach (array_filter([$seller->address_line1, $seller->address_line2]) as $line)
                        {{ $line }}<br>
                    @endforeach
                    {{ collect([$seller->city, $seller->state, $seller->pincode])->filter()->implode(', ') }}
                </div>
                <div class="muted">
                    @if ($seller->phone) Phone: {{ $seller->phone }} @endif
                    @if ($seller->email) &nbsp;·&nbsp; {{ $seller->email }} @endif
                </div>
                @if ($gst && $seller->gstin)
                    <div class="bold">GSTIN: {{ $seller->gstin }}</div>
                @endif
            </td>
            <td class="right">
                <div class="doc-title">{{ $doc->heading() }}</div>
                @unless ($gst)
                    <div class="muted">(Not a GST invoice)</div>
                @endunless

                <table style="margin-top:6px">
                    <tr>
                        <td class="right muted">Invoice No.</td>
                        <td class="right bold" style="width:52%">{{ $invoice->invoice_number ?? 'DRAFT' }}</td>
                    </tr>
                    <tr>
                        <td class="right muted">Date</td>
                        <td class="right">{{ optional($invoice->invoice_date)->format('d M Y') }}</td>
                    </tr>
                    @if ($gst && $invoice->place_of_supply_state_code)
                        <tr>
                            <td class="right muted">Place of supply</td>
                            <td class="right">{{ $invoice->place_of_supply_state_code }}</td>
                        </tr>
                    @endif
                </table>

                @if ($doc->isCancelled())
                    <div style="margin-top:8px"><span class="cancelled-badge">CANCELLED</span></div>
                @endif
            </td>
        </tr>
    </table>

    @if ($doc->isCancelled() && $invoice->cancellation_reason)
        <div style="margin-bottom:8px; color:#b42318;">
            <span class="bold">Cancelled:</span> {{ $invoice->cancellation_reason }}
        </div>
    @endif

    {{-- ------------------------------------------------------------ parties --}}
    <table style="margin-bottom:10px">
        <tr>
            <td class="party">
                <div class="party-label">Billed to</div>
                @if ($invoice->customer)
                    <div class="bold">{{ $invoice->customer->name }}</div>
                    <div class="muted">
                        @if ($invoice->customer->address){{ $invoice->customer->address }}<br>@endif
                        {{ collect([$invoice->customer->city, $invoice->customer->state, $invoice->customer->pincode])->filter()->implode(', ') }}
                    </div>
                    @if ($invoice->customer->phone)
                        <div class="muted">Phone: {{ $invoice->customer->phone }}</div>
                    @endif
                    @if ($gst && $invoice->customer->gstin)
                        <div class="bold">GSTIN: {{ $invoice->customer->gstin }}</div>
                    @endif
                @else
                    <div>Walk-in customer</div>
                @endif
            </td>
            <td style="width:8px"></td>
            <td class="party">
                <div class="party-label">Details</div>
                @if ($gst)
                    <div>Supply: {{ $interState ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)' }}</div>
                @endif
                @if ($invoice->notes)
                    <div class="muted">{{ $invoice->notes }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- -------------------------------------------------------------- lines --}}
    <table class="lines">
        <thead>
        <tr>
            <th style="width:22px" class="center">#</th>
            <th>Description</th>
            @if ($showHsn)<th style="width:58px">HSN</th>@endif
            <th style="width:52px" class="right">Qty</th>
            <th style="width:66px" class="right">Rate</th>
            @if ($gst)
                <th style="width:72px" class="right">Taxable</th>
                @if ($interState)
                    <th style="width:82px" class="right">IGST</th>
                @else
                    <th style="width:74px" class="right">CGST</th>
                    <th style="width:74px" class="right">SGST</th>
                @endif
            @endif
            <th style="width:80px" class="right">Amount</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($doc->lines() as $i => $item)
            <tr>
                <td class="center muted">{{ $i + 1 }}</td>
                <td>
                    {{-- Snapshot values: never the live product. --}}
                    <span class="bold">{{ $item->product_name }}</span><br>
                    <span class="muted">{{ $item->sku }}</span>
                </td>
                @if ($showHsn)<td>{{ $item->hsn_code ?? '—' }}</td>@endif
                <td class="right num">{{ F::quantity((string) $item->quantity) }} {{ $item->unit }}</td>
                <td class="right num">{{ F::amount((string) $item->unit_price) }}</td>
                @if ($gst)
                    <td class="right num">{{ F::amount((string) $item->taxable_amount) }}</td>
                    @if ($interState)
                        <td class="right num">
                            {{ F::amount((string) $item->igst_amount) }}
                            <br><span class="muted">{{ F::rate((string) $item->igst_rate) }}%</span>
                        </td>
                    @else
                        <td class="right num">
                            {{ F::amount((string) $item->cgst_amount) }}
                            <br><span class="muted">{{ F::rate((string) $item->cgst_rate) }}%</span>
                        </td>
                        <td class="right num">
                            {{ F::amount((string) $item->sgst_amount) }}
                            <br><span class="muted">{{ F::rate((string) $item->sgst_rate) }}%</span>
                        </td>
                    @endif
                @endif
                <td class="right num bold">{{ F::amount((string) $item->line_total) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ 5 + ($showHsn ? 1 : 0) + ($gst ? 1 + $taxColumns : 0) }}" class="center muted" style="padding:14px">
                    This invoice has no lines.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    {{-- ------------------------------------------------- summary and totals --}}
    <table style="margin-top:10px">
        <tr>
            <td style="width:52%">
                @if ($gst && $taxSummary !== [])
                    <table class="summary">
                        <thead>
                        <tr>
                            <th>GST %</th>
                            <th class="right">Taxable</th>
                            @if ($interState)
                                <th class="right">IGST</th>
                            @else
                                <th class="right">CGST</th>
                                <th class="right">SGST</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($taxSummary as $row)
                            <tr>
                                <td>{{ F::rate($row['rate']) }}%</td>
                                <td class="right num">{{ F::amount($row['taxable']) }}</td>
                                @if ($interState)
                                    <td class="right num">{{ F::amount($row['igst']) }}</td>
                                @else
                                    <td class="right num">{{ F::amount($row['cgst']) }}</td>
                                    <td class="right num">{{ F::amount($row['sgst']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </td>
            <td style="width:12px"></td>
            <td>
                <table class="totals">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="right num">{{ F::amount((string) $invoice->subtotal) }}</td>
                    </tr>

                    @if (bccomp((string) $invoice->discount_amount, '0', 2) > 0)
                        <tr>
                            <td class="label">Discount</td>
                            <td class="right num">- {{ F::amount((string) $invoice->discount_amount) }}</td>
                        </tr>
                    @endif

                    @if ($gst)
                        {{-- Taxable value is shown only on a GST bill: on a
                             non-GST bill it would equal the subtotal and imply
                             a tax that is not being charged. --}}
                        <tr>
                            <td class="label">Taxable value</td>
                            <td class="right num">{{ F::amount((string) $invoice->taxable_amount) }}</td>
                        </tr>

                        @if ($interState)
                            <tr>
                                <td class="label">IGST</td>
                                <td class="right num">{{ F::amount((string) $invoice->igst_amount) }}</td>
                            </tr>
                        @else
                            <tr>
                                <td class="label">CGST</td>
                                <td class="right num">{{ F::amount((string) $invoice->cgst_amount) }}</td>
                            </tr>
                            <tr>
                                <td class="label">SGST</td>
                                <td class="right num">{{ F::amount((string) $invoice->sgst_amount) }}</td>
                            </tr>
                        @endif
                    @endif

                    @if (bccomp((string) $invoice->round_off, '0', 2) !== 0)
                        <tr>
                            <td class="label">Round off</td>
                            <td class="right num">{{ F::amount((string) $invoice->round_off) }}</td>
                        </tr>
                    @endif

                    <tr class="grand">
                        <td>Grand total</td>
                        <td class="right num">Rs. {{ F::amount((string) $invoice->grand_total) }}</td>
                    </tr>

                    <tr>
                        <td class="label">Paid</td>
                        <td class="right num">{{ F::amount((string) $invoice->paid_amount) }}</td>
                    </tr>
                    <tr>
                        <td class="label bold">Balance due</td>
                        <td class="right num bold">{{ F::amount($doc->balanceDue()) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ------------------------------------------------------------- footer --}}
    <table class="foot">
        <tr>
            <td style="width:62%">
                @if ($seller->bank_name || $seller->upi_id)
                    <div class="bold">Payment details</div>
                    @if ($seller->bank_name)
                        <div>{{ $seller->bank_name }}
                            @if ($seller->bank_branch) — {{ $seller->bank_branch }} @endif
                        </div>
                    @endif
                    @if ($seller->bank_account_number)
                        <div>A/c: {{ $seller->bank_account_number }}
                            @if ($seller->bank_ifsc) &nbsp; IFSC: {{ $seller->bank_ifsc }} @endif
                        </div>
                    @endif
                    @if ($seller->upi_id)<div>UPI: {{ $seller->upi_id }}</div>@endif
                @endif

                @if ($invoice->terms ?? $seller->invoice_terms)
                    <div style="margin-top:7px">
                        <span class="bold">Terms</span><br>
                        {{ $invoice->terms ?? $seller->invoice_terms }}
                    </div>
                @endif
            </td>
            <td class="right">
                <div>For {{ $seller->business_name }}</div>
                <div class="sign" style="margin-left:auto">Authorised signatory</div>
            </td>
        </tr>
    </table>

    <div class="center muted" style="margin-top:12px; font-size:8px;">
        This is a computer-generated document.
    </div>
</div>
</body>
</html>

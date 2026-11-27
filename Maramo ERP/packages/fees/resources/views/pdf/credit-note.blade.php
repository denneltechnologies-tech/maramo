@php
    /**
     * Credit note (fee refunds). Deliberately the SAME document family as
     * pdf/fee-receipt.blade.php — same dompdf setup, same A4 / A5 / thermal
     * switch, same header, same currency handling — because the office prints
     * both on the same printer and hands both to the same parent.
     *
     * It is NOT a receipt with a stamp on it. That was all the old whole-receipt
     * revert could produce: a reprint of the ORIGINAL receipt marked "REVERTED",
     * which names the money coming IN and leaves the family holding a document
     * that says the opposite of what happened. This one names the money going
     * BACK: which head it came out of, why (a reason code, not a clerk's
     * sentence), who approved it, how it was returned, and what credit the
     * family is left holding.
     *
     * @var \Classivo\Fees\Models\FeeRefund $refund
     */
    $thermal = ($size ?? 'a4') === 'thermal';
    $fmt = fn ($v) => ($currencySymbol ?? '₹').' '.number_format((float) $v, 2);
    $decisions = $decisions ?? collect();
    $issued = $refund->status === 'issued';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Credit Note — {{ $refund->creditNoteLabel() }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; margin: 0; padding: {{ $thermal ? '8px 10px' : '26px 32px' }};
               font-size: {{ $thermal ? '10px' : '12px' }}; }
        .wrap { @if(! $thermal) border: 2px solid #334155; padding: 18px 22px; @endif }
        .header { text-align: center; border-bottom: 2px solid #334155; padding-bottom: 8px; margin-bottom: 10px; }
        .school { font-size: {{ $thermal ? '13px' : '20px' }}; font-weight: bold; }
        .branch { font-size: {{ $thermal ? '9px' : '11px' }}; color: #475569; margin-top: 2px; }
        .title { text-align: center; font-size: {{ $thermal ? '11px' : '14px' }}; font-weight: bold;
                 text-transform: uppercase; letter-spacing: 1px; margin: 6px 0 10px; }
        table.meta { width: 100%; margin-bottom: 10px; font-size: {{ $thermal ? '9px' : '12px' }}; }
        table.meta td { padding: 1px 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: {{ $thermal ? '9px' : '12px' }}; }
        table.lines th, table.lines td { border: 1px solid #cbd5e1; padding: {{ $thermal ? '3px 4px' : '6px 8px' }}; text-align: left; }
        table.lines th { background: #f1f5f9; }
        table.lines td.num, table.lines th.num { text-align: right; }
        .summary { width: 100%; border-collapse: collapse; font-size: {{ $thermal ? '9px' : '12px' }}; }
        .summary td { padding: 3px 6px; }
        .summary td.label { color: #475569; }
        .summary td.val { text-align: right; font-weight: bold; }
        .grand { font-size: {{ $thermal ? '11px' : '14px' }}; }
        .draft { color: #b45309; font-weight: bold; border: 1px dashed #b45309; padding: 3px 6px; text-align: center; margin-bottom: 8px; }
        .reason { border: 1px solid #cbd5e1; background: #f8fafc; padding: {{ $thermal ? '4px 5px' : '7px 9px' }}; margin-bottom: 10px; }
        .trail { width: 100%; border-collapse: collapse; font-size: {{ $thermal ? '8px' : '10px' }}; margin-top: 6px; }
        .trail td { padding: 2px 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .trail td.who { color: #475569; white-space: nowrap; }
        .foot { margin-top: {{ $thermal ? '18px' : '38px' }}; width: 100%; font-size: {{ $thermal ? '9px' : '11px' }}; }
        .foot td { vertical-align: bottom; }
        .sign { border-top: 1px solid #334155; padding-top: 3px; text-align: center; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="school">{{ $school?->name ?? config('app.name') }}</div>
        <div class="branch">
            {{ $branch?->name }}@if($branch?->address) — {{ $branch->address }}@endif
        </div>
    </div>

    <div class="title">Credit Note</div>

    {{-- A credit note with no number has not been issued, and printing one that
         looks final would let an un-approved refund walk out of the office as a
         document. --}}
    @unless($issued)
        <div class="draft">
            NOT YET ISSUED — {{ \Classivo\Fees\Models\FeeRefund::STATUSES[$refund->status] ?? ucfirst($refund->status) }}. This copy is for internal review only.
        </div>
    @endunless

    <table class="meta">
        <tr>
            <td><strong>Credit Note No:</strong> {{ $refund->creditNoteLabel() }}</td>
            <td style="text-align:right"><strong>Date:</strong> {{ ($refund->issued_at ?? $refund->requested_at ?? $refund->created_at)?->format('d M Y') }}</td>
        </tr>
        <tr>
            <td><strong>Student:</strong> {{ $student?->full_name }}</td>
            <td style="text-align:right"><strong>Admission No:</strong> {{ $student?->admission_no }}</td>
        </tr>
        <tr>
            <td><strong>Class-Section:</strong> {{ $student?->current_class_section?->name ?? '—' }}</td>
            <td style="text-align:right"><strong>Against Receipt:</strong> {{ $payment?->receipt_no ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Receipt Date:</strong> {{ $payment?->payment_date?->format('d M Y') ?? '—' }}</td>
            <td style="text-align:right"><strong>Refunded By:</strong> {{ $refund->modeLabel() }}</td>
        </tr>
    </table>

    <div class="reason">
        <strong>Reason:</strong> {{ $refund->reasonLabel() }}
        @if($refund->reason_detail)
            <br><span class="muted">{{ $refund->reason_detail }}</span>
        @endif
        @if($refund->reference_no)
            <br><span class="muted">Reference: {{ $refund->reference_no }}</span>
        @endif
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>#</th>
                <th>Fee head</th>
                <th>Dues restored</th>
                <th class="num">Refunded</th>
            </tr>
        </thead>
        <tbody>
            @foreach($refund->lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->headLabel() }}</td>
                    {{-- Spelled out per line, because it is the one thing a family
                         will ask about: money back AND the fee owing again is
                         correct for a TC, and wrong for an over-payment. --}}
                    <td>{{ $line->restore_dues ? 'Yes — this fee is payable again' : 'No — over-payment returned' }}</td>
                    <td class="num">{{ $fmt($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr><td class="label">Originally paid on {{ $payment?->receipt_no ?? 'receipt' }}</td><td class="val">{{ $fmt($payment?->paid_amount ?? 0) }}</td></tr>
        <tr class="grand"><td class="label">Total refunded</td><td class="val">{{ $fmt($refund->amount) }}</td></tr>
    </table>

    {{-- THE AUDIT TRAIL, ON THE DOCUMENT ITSELF. The durable rows live in
         `fee_refund_decisions`; printing them here means the copy in the family's
         hands and the copy in the database say the same thing. --}}
    @if($decisions->isNotEmpty() && ! $thermal)
        <p style="margin:12px 0 2px"><strong>Approval trail</strong></p>
        <table class="trail">
            @foreach($decisions as $decision)
                <tr>
                    <td class="who">{{ $decision->created_at?->format('d M Y H:i') }}</td>
                    <td class="who">{{ \Illuminate\Support\Str::headline($decision->decision) }}</td>
                    <td class="who">{{ $decision->decider?->name ?? 'System' }}</td>
                    <td>{{ $decision->remark }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table class="foot">
        <tr>
            <td>
                Requested by: {{ $refund->requestedBy?->name ?? '—' }}<br>
                Approved by: {{ $refund->approvedBy?->name ?? ((int) $refund->required_approvals === 0 ? 'Auto-approved (below threshold)' : '—') }}<br>
                Issued by: {{ $refund->issuedBy?->name ?? '—' }}
            </td>
            <td style="text-align:right; width:45%"><div class="sign">Authorised Signature</div></td>
        </tr>
    </table>
    <p class="muted" style="text-align:center; margin-top:10px">This is a computer-generated credit note.</p>
</div>
</body>
</html>

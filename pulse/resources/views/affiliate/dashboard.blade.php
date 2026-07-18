@extends('layouts.app')
@section('title', 'Affiliate Dashboard — TheTrueDefender')

@section('content')
  <main class="page-main" style="max-width:900px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <h1 style="margin-bottom:4px">Welcome, {{ $affiliate->name }}</h1>
        <p class="page-sub" style="margin:0">Your affiliate dashboard</p>
      </div>
      <form method="POST" action="{{ route('affiliate.logout') }}">
        @csrf
        <button type="submit" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:inherit;padding:8px 16px;border-radius:8px;cursor:pointer">Sign out</button>
      </form>
    </div>

    {{-- Referral link --}}
    <div style="background:rgba(227,59,78,.08);border:1px solid rgba(227,59,78,.3);border-radius:12px;padding:18px;margin:22px 0">
      <strong>Your referral link</strong>
      <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
        <input type="text" id="refLink" readonly value="{{ $affiliate->referralUrl() }}"
               style="flex:1;min-width:260px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:10px 12px;color:inherit;font-size:.9rem" />
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('refLink').value).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)})"
                style="background:var(--accent,#e33b4e);border:none;color:#fff;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600">Copy</button>
      </div>
      <p style="font-size:.8rem;opacity:.65;margin-top:8px">
        Add <code>?ref={{ $affiliate->code }}</code> to ANY page — an article, a product, the homepage. Example:
        <code>{{ url('/post/some-story') }}?ref={{ $affiliate->code }}</code>
      </p>
    </div>

    {{-- Stats --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:26px">
      @foreach ([
        ['Valid clicks (all time)', number_format($validClicks)],
        ['Valid clicks (30 days)', number_format($clicks30)],
        ['Traffic earnings', '$' . number_format($clickEarnings, 2)],
        ['Sale commissions', '$' . number_format($saleEarnings, 2)],
        ['Total paid out', '$' . number_format($totalPaid, 2)],
        ['Balance', '$' . number_format($balance, 2)],
      ] as [$label, $value])
        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:1.5rem;font-weight:800">{{ $value }}</div>
          <div style="font-size:.78rem;opacity:.65;margin-top:4px">{{ $label }}</div>
        </div>
      @endforeach
    </div>

    <p style="font-size:.85rem;opacity:.7;margin-bottom:26px">
      Your rates: <strong>${{ number_format($affiliate->effectiveRatePer1000() * $affiliate->effectiveSharePct() / 100, 2) }} per 1,000 valid visits</strong>
      ({{ number_format($affiliate->effectiveSharePct(), 0) }}% of ${{ number_format($affiliate->effectiveRatePer1000(), 2) }} RPM)
      &nbsp;•&nbsp; <strong>{{ number_format($affiliate->effectiveSaleCommissionPct(), 0) }}% commission</strong> on referred shop orders.
    </p>

    {{-- Recent conversions --}}
    <h2 style="font-size:1.1rem;margin-bottom:10px">Recent sale commissions</h2>
    @if($conversions->isEmpty())
      <p style="font-size:.85rem;opacity:.6;margin-bottom:24px">No referred orders yet — share product links to earn commissions.</p>
    @else
      <div style="overflow-x:auto;margin-bottom:24px">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <tr style="text-align:left;opacity:.6"><th style="padding:8px">Date</th><th>Order total</th><th>Commission</th><th>Status</th></tr>
          @foreach($conversions as $c)
            <tr style="border-top:1px solid rgba(255,255,255,.08)">
              <td style="padding:8px">{{ $c->created_at->format('M j, Y') }}</td>
              <td>${{ number_format($c->order_total, 2) }}</td>
              <td>${{ number_format($c->commission_amount, 2) }} ({{ number_format($c->commission_pct, 0) }}%)</td>
              <td>{{ ucfirst($c->status) }}</td>
            </tr>
          @endforeach
        </table>
      </div>
    @endif

    {{-- Payout history --}}
    <h2 style="font-size:1.1rem;margin-bottom:10px">Payout history</h2>
    @if($payouts->isEmpty())
      <p style="font-size:.85rem;opacity:.6">No payouts yet. Payouts are processed manually — make sure your payout method is on file (contact us to update it).</p>
    @else
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <tr style="text-align:left;opacity:.6"><th style="padding:8px">Date</th><th>Amount</th><th>Method</th><th>Note</th></tr>
          @foreach($payouts as $p)
            <tr style="border-top:1px solid rgba(255,255,255,.08)">
              <td style="padding:8px">{{ $p->paid_at->format('M j, Y') }}</td>
              <td>${{ number_format($p->amount, 2) }}</td>
              <td>{{ $p->method ?? '—' }}</td>
              <td>{{ $p->note ?? '—' }}</td>
            </tr>
          @endforeach
        </table>
      </div>
    @endif

    <p style="font-size:.78rem;opacity:.5;margin-top:28px">
      Reminder: never click ads on our site and never encourage anyone to click ads — only genuine visits count, and ad-click fraud terminates the account.
    </p>
  </main>
@endsection

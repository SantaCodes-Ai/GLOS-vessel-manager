@php($expiry = $certificate->expiry())
<span class="expiry-badge expiry-{{ $expiry->status }}">{{ $expiry->label() }}</span>
@if ($expiry->date)<span class="expiry-meta">{{ $expiry->date }}</span>@endif
@if ($expiry->days !== null)
    <span class="expiry-meta">{{ $expiry->days < 0 ? abs($expiry->days).' days overdue' : $expiry->days.' days remaining' }}</span>
@elseif ($expiry->status === 'unknown')
    <span class="expiry-meta">Missing or inconsistent expiry data</span>
@endif

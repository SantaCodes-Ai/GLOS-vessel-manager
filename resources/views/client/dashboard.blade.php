@extends('layouts.client')
@section('title', 'Staff Dashboard')
@section('content')
    <p class="eyebrow">Staff Dashboard</p>
    @include('shared.dashboard-greeting')
    <p class="welcome">Review certificates and documents for your assigned vessels.</p>
    <dl class="summary-grid" aria-label="Your record totals">
        <div class="summary-card"><dt>Assigned vessels</dt><dd>{{ number_format($totalVessels) }}</dd></div>
        <div class="summary-card"><dt>Certificates</dt><dd>{{ number_format($totalCertificates) }}</dd></div>
        @foreach($statusCounts as $status => $count)
            <div class="summary-card"><dt><span class="expiry-badge expiry-{{ $status }}">{{ $status === 'expiring-soon' ? 'Expiring Soon' : ucfirst($status) }}</span> certificates</dt><dd>{{ number_format($count) }}</dd></div>
        @endforeach
    </dl>
    <section class="panel" aria-labelledby="assigned-heading">
        <div class="panel-heading"><h2 id="assigned-heading">Assigned vessels</h2><p>Up to five vessels. <a class="vessel-link" href="{{ route('client.vessels.index') }}">View all assigned vessels</a></p></div>
        @include('client.vessels-table')
    </section>
    <section class="panel" aria-labelledby="attention-heading">
        <div class="panel-heading"><h2 id="attention-heading">Certificates Requiring Attention</h2><p>Up to ten certificates, earliest expiry first. Unknown expiry data is counted separately above.</p></div>
        @if($attentionCertificates->isEmpty())
            <div class="empty-state"><strong>No certificates requiring attention</strong><p>No current certificates for your assigned vessels are expired or due within 30 days.</p></div>
        @else
            @include('client.certificates-table', ['certificates' => $attentionCertificates])
        @endif
    </section>
@endsection

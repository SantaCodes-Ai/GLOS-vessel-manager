@if($certificates->isEmpty())
    <div class="empty-state"><strong>No certificates found</strong><p>There are no matching certificates for your assigned vessels.</p></div>
@else
    <div class="table-scroll" tabindex="0" role="region" aria-label="Assigned vessel certificates">
        <table><caption>Certificates for assigned vessels</caption><thead><tr><th scope="col">Certificate</th><th scope="col">Vessel</th><th scope="col">Current version / Reference</th><th scope="col">Expiry</th></tr></thead>
            <tbody>@foreach($certificates as $certificate)<tr>
                <td><a class="vessel-link" href="{{ route('client.certificates.show', $certificate) }}">{{ $certificate->certificateType?->name ?? 'Certificate details' }}</a></td>
                <td>{{ $certificate->vessel?->vessel_name ?? 'Not recorded' }}</td>
                <td>{{ $certificate->currentVersion ? 'Version '.$certificate->currentVersion->version_number : 'No version' }}<br>{{ $certificate->currentVersion?->certificate_number ?? 'Not recorded' }}</td>
                <td>@include('admin.certificates.expiry')</td>
            </tr>@endforeach</tbody>
        </table>
    </div>
@endif

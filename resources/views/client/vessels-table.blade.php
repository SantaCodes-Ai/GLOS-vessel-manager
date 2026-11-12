@if($vessels->isEmpty())
    <div class="empty-state"><strong>No assigned vessels found</strong><p>Try another search, or contact Global Link Ocean Streams about your vessel assignments.</p></div>
@else
    <div class="table-scroll" tabindex="0" role="region" aria-label="Assigned vessels">
        <table><caption>Your assigned vessels</caption><thead><tr><th scope="col">Vessel</th><th scope="col">IMO number</th><th scope="col">Type / Flag</th><th scope="col">Status</th><th scope="col">Certificates</th></tr></thead>
            <tbody>@foreach($vessels as $vessel)<tr>
                <td><a class="vessel-link" href="{{ route('client.vessels.show', $vessel) }}">{{ $vessel->vessel_name }}</a></td>
                <td>{{ $vessel->imo_number ?? 'Not recorded' }}</td><td>{{ $vessel->vessel_type }} / {{ $vessel->flag_state }}</td><td>{{ ucfirst($vessel->vessel_status) }}</td><td>{{ $vessel->certificates_count }}</td>
            </tr>@endforeach</tbody>
        </table>
    </div>
@endif

{{--
    "Current" column of a DNS record (E-116, E-153): always the same lines,
    one below the other: Hetzner zone (when the zone is at Hetzner), name
    servers, resolver and TTL. Expects $row (SiteDns row) and $withZone.
--}}
@php
    $text = fn (?array $values) => $values === null ? __('dns.no_answer') : ($values === [] ? __('dns.none') : implode(', ', $values));
    $lines = array_filter([
        'hetzner' => $withZone ? $row['zone'] : false,
        'authoritative' => $row['authoritative'],
        'resolver' => $row['resolver'],
    ], fn ($values) => $values !== false);
@endphp
@foreach ($lines as $source => $values)
    <div class="text-nowrap">
        <span class="small text-secondary">{{ __('dns.sources.'.$source) }}:</span>
        <span @class(['text-secondary' => $values === null || $values === []])>{{ $text($values) }}</span>
    </div>
@endforeach
@if ($withZone)
    <div class="text-nowrap">
        <span class="small text-secondary">{{ __('dns.sources.ttl') }}:</span>
        <span @class(['text-secondary' => ! $row['ttl']])>{{ $row['ttl'] ? $row['ttl'].' s' : '–' }}</span>
    </div>
@endif

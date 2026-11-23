{{--
    A point in time in the time zone of the interface (E-059). The database
    stores UTC. Shows a dash for null. :stacked="true" puts the time under the
    date, which keeps table columns narrow.
    <x-time :value="$task->started_at" />
--}}
@props(['value', 'stacked' => false])
@if ($value)
    @php($local = $value->copy()->setTimezone(config('owpanel.timezone')))
    <time {{ $attributes }} datetime="{{ $value->toIso8601String() }}">
        @if ($stacked)
            <span class="d-block">{{ $local->isoFormat('L') }}</span>
            <span class="d-block small text-secondary">{{ $local->isoFormat('LTS') }}</span>
        @else
            {{ $local->isoFormat('L LTS') }}
        @endif
    </time>
@else
    <span class="text-secondary">–</span>
@endif

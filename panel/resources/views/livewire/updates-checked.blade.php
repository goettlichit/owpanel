<div @if ($waiting) wire:poll.5s @endif>
    @if ($waiting)
        <span class="d-inline-flex align-items-center gap-2 text-secondary">
            <span class="spinner-border spinner-border-sm" role="status"></span>{{ __('servers.updates.automatic.checking') }}
        </span>
    @else
        <x-time :value="$server->updates_checked_at" />
    @endif
</div>

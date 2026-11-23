{{--
    Update state of a server as badges (E-076): restart needed, pending
    security or other updates, or up to date. A restart and pending updates
    can show together.
    <x-update-state :server="$server" />
--}}
@props(['server'])
@php
    $state = $server->updateState();
    $pending = $server->pendingUpdates();
    $security = $server->securityUpdates();
@endphp
<span {{ $attributes->class(['d-inline-flex flex-wrap gap-1']) }}>
    @if ($state === 'reboot')
        <span class="badge bg-orange-lt"><x-icon name="refresh-alert" class="me-1" />{{ __('servers.update_state.reboot') }}</span>
    @endif
    @if ($security > 0)
        <span class="badge bg-red-lt"><x-icon name="shield-exclamation" class="me-1" />{{ trans_choice('servers.update_state.security', $security, ['count' => $security]) }}</span>
    @elseif ($pending > 0)
        <span class="badge bg-orange-lt"><x-icon name="package" class="me-1" />{{ trans_choice('servers.update_state.pending', $pending, ['count' => $pending]) }}</span>
    @elseif ($state === 'current')
        <span class="badge bg-green-lt"><x-icon name="circle-check" class="me-1" />{{ __('servers.update_state.current') }}</span>
    @elseif ($state === 'unknown')
        <span class="badge bg-secondary-lt"><x-icon name="help-circle" class="me-1" />{{ __('servers.update_state.unknown') }}</span>
    @endif
</span>

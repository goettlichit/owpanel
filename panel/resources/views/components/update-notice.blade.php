{{--
    The central update hint of a server as a banner (E-076): green when the
    system is up to date, orange for pending updates or a needed restart,
    red for pending security updates.
    <x-update-notice :server="$server" :link="true" />
--}}
@props(['server', 'link' => false])
@php
    $state = $server->updateState();
    $pending = $server->pendingUpdates();
    $security = $server->securityUpdates();
    $reboot = $server->updates['reboot'] ?? [];
    $tone = match (true) {
        $security > 0 => 'danger',
        $state === 'reboot' || $pending > 0 => 'warning',
        $state === 'current' => 'success',
        default => 'secondary',
    };
    $icon = match ($tone) {
        'danger' => 'shield-exclamation',
        'warning' => $state === 'reboot' ? 'refresh-alert' : 'package',
        'success' => 'circle-check',
        default => 'help-circle',
    };
@endphp
<div {{ $attributes->class(['alert', 'alert-'.$tone]) }} role="status">
    <div class="d-flex align-items-start gap-2 w-100">
        <x-icon :name="$icon" class="mt-1" />
        <div class="flex-fill">
            @if ($state === 'reboot')
                <div class="fw-medium">
                    @if (! empty($reboot['kernel_installed']) && ! empty($reboot['kernel_running']))
                        {{ __('servers.updates.state.reboot_kernel', ['running' => $reboot['kernel_running'], 'installed' => $reboot['kernel_installed']]) }}
                    @else
                        {{ __('servers.updates.state.reboot') }}
                    @endif
                </div>
                @if (! empty($reboot['packages']))
                    <div class="small">{{ __('servers.updates.state.reboot_packages', ['packages' => implode(', ', $reboot['packages'])]) }}</div>
                @endif
            @endif
            @if ($security > 0)
                <div @class(['fw-medium' => $state !== 'reboot'])>{{ trans_choice('servers.updates.state.security', $pending, ['count' => $pending, 'security' => $security]) }}</div>
            @elseif ($pending > 0)
                <div @class(['fw-medium' => $state !== 'reboot'])>{{ trans_choice('servers.updates.state.pending', $pending, ['count' => $pending]) }}</div>
            @elseif ($state === 'current')
                <div class="fw-medium">{{ __('servers.updates.state.current') }}</div>
            @elseif ($state === 'unknown')
                <div>{{ __('servers.updates.state.unknown') }}</div>
            @endif
        </div>
        @if ($link)
            <a href="{{ route('servers.updates', $server) }}" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                <x-icon name="package" class="me-1" />{{ __('servers.updates.show') }}
            </a>
        @endif
    </div>
</div>

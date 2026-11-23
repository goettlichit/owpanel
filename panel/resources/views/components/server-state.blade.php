{{--
    Online state of a server with the time of the last answer (E-075).
    <x-server-state :server="$server" />
--}}
@props(['server', 'detail' => true])
@php($state = $server->onlineState())
<div {{ $attributes }}>
    <x-status-badge :status="$state" :title="$server->checkErrorMessage()" />
    @if ($detail)
        <div class="small text-secondary mt-1">
            @if ($server->last_seen_at)
                {{ __('servers.state.last_seen', ['time' => $server->last_seen_at->locale(app()->getLocale())->diffForHumans()]) }}
            @elseif ($state === 'unknown')
                {{ __('servers.state.never_checked') }}
            @else
                {{ __('servers.state.never_seen') }}
            @endif
        </div>
    @endif
</div>

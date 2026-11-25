<div @if ($running) wire:poll.5s @endif>
    @php($error = $update['error'] !== null ? (\Illuminate\Support\Facades\Lang::has('tasks.errors.'.$update['error']) ? __('tasks.errors.'.$update['error']) : $update['error']) : null)
    @if ($running)
        <div class="alert alert-info mb-0" role="status">
            <div class="d-flex align-items-center gap-2">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span>{{ __('servers.updates.panel.state.running', ['version' => $update['version'], 'from' => $update['from']]) }}</span>
            </div>
            <div class="small text-secondary mt-1">{{ __('servers.updates.panel.state.running_hint') }}</div>
        </div>
    @elseif ($update['state'] === 'succeeded')
        <div class="text-success small">
            <x-icon name="circle-check" class="me-1" />{{ __($update['auto'] ? 'servers.updates.panel.state.succeeded_auto' : 'servers.updates.panel.state.succeeded', ['version' => $update['version'], 'from' => $update['from']]) }}
            <x-time :value="$update['finished_at']" />
        </div>
    @elseif ($update['state'] === 'failed')
        <div class="text-danger small">
            <x-icon name="circle-x" class="me-1" />{{ __('servers.updates.panel.state.failed', ['version' => $update['version'], 'error' => $error]) }}
            <x-time :value="$update['finished_at']" />
            @if ($update['task'] !== null)
                · <a href="{{ route('tasks.show', $update['task']) }}">{{ __('servers.updates.panel.state.task') }}</a>
            @endif
        </div>
    @elseif ($update['state'] === 'postponed')
        <div class="text-secondary small">
            <x-icon name="clock" class="me-1" />{{ __('servers.updates.panel.state.postponed', ['version' => $update['version']]) }}
            <x-time :value="$update['finished_at']" />
        </div>
    @else
        <span class="text-secondary">–</span>
    @endif
</div>

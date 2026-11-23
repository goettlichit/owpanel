<div @if ($connecting) wire:poll.3s @endif>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        @if ($connecting)
            <span class="badge bg-blue-lt"><span class="spinner-border spinner-border-sm me-1" role="status"></span>{{ __('settings.backup.connecting') }}</span>
        @elseif ($server->backupActive())
            <span class="badge bg-green-lt">{{ __('settings.backup.ready') }}</span>
        @elseif ($server->backupConfigured())
            <span class="badge bg-orange-lt">{{ __('settings.backup.not_ready') }}</span>
        @else
            <span class="badge bg-secondary-lt">{{ __('settings.backup.off') }}</span>
        @endif
    </div>
    @if ($server->backupConfigured() && ! $server->backup_ready && ! $connecting && $setup && $setup->status === App\Enums\TaskStatus::Failed)
        <div class="small text-danger mt-1">{{ $setup->errorMessage() }} <a href="{{ route('tasks.show', $setup) }}">{{ __('settings.backup.show_task') }}</a></div>
    @endif
</div>

<div @unless ($task->isFinished()) wire:poll.2s @endunless class="d-inline-flex">
    {{-- Only administrators get the link to the task log (E-143). --}}
    @if ($link)
        <a href="{{ route('tasks.show', $task) }}" class="text-reset text-decoration-none d-inline-flex align-items-center gap-2 small">
            @if ($label !== '')<span class="text-secondary">{{ $label }}</span>@endif
            <x-status-badge :status="$task->status->value" />
        </a>
    @else
        <span class="d-inline-flex align-items-center gap-2 small">
            @if ($label !== '')<span class="text-secondary">{{ $label }}</span>@endif
            <x-status-badge :status="$task->status->value" />
        </span>
    @endif
</div>

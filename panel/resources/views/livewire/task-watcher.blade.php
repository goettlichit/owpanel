{{--
    Follows the open server tasks of the page (E-152). The script on the
    page puts the spinner on the button that started a task; if it finds
    none, the line below appears instead.
--}}
<div @if ($open->isNotEmpty()) wire:poll.2s="check" @endif
    data-ow-task-watcher data-open="{{ $open->count() }}" data-scripts="{{ implode(' ', $scripts) }}">
    @if ($own)
        <div class="d-none d-flex align-items-center gap-2 text-secondary mb-3" data-ow-task-fallback role="status">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            {{ __('tasks.feedback.running', ['task' => __('tasks.scripts.'.$own->script->value)]) }}
        </div>
    @endif
</div>

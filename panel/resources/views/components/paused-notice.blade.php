{{--
    Banner on every page while a queue of the panel is paused (E-133), only
    for administrators: nothing waiting there runs until it is resumed.
--}}
@php($paused = auth()->user()?->isAdmin() ? \App\Workers\PanelWorkers::pausedQueues() : [])
@if ($paused !== [])
    <div class="alert alert-warning d-print-none" role="status">
        <div class="d-flex align-items-center gap-2 flex-wrap w-100">
            <x-icon name="player-pause" />
            <div class="flex-fill">
                {{ __('workers.banner', ['names' => collect($paused)->map(fn ($queue) => __('workers.names.'.$queue))->join(', ')]) }}
            </div>
            <a href="{{ route('workers.index') }}" class="btn btn-sm btn-warning">{{ __('workers.banner_open') }}</a>
        </div>
    </div>
@endif

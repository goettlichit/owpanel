<x-layouts.app :title="__('workers.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('workers.title')" :pretitle="__('ui.nav.system')" />

    @php
        $accent = fn (string $state) => match ($state) {
            'running', 'busy' => 'green',
            'paused' => 'orange',
            'unresponsive' => 'red',
            default => 'blue',
        };
        $tone = fn (string $state) => match ($state) {
            'running', 'busy' => 'ok',
            'paused' => 'warning',
            'unresponsive' => 'danger',
            default => 'neutral',
        };
    @endphp

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8 d-flex flex-column gap-3">
            @foreach ($workers as $worker)
                <x-card :title="__('workers.names.'.$worker['name'])" :accent="$accent($worker['state'])">
                    <x-slot:actions>
                        <x-badge :tone="$tone($worker['state'])">{{ __('workers.states.'.$worker['state']) }}</x-badge>
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('workers.descriptions.'.$worker['name']) }}</p>
                    <dl class="row mb-0">
                        <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.unit') }}</dt>
                        <dd class="col-sm-7"><code>{{ $worker['unit'] }}</code></dd>
                        <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.processes') }}</dt>
                        <dd class="col-sm-7">{{ $worker['processes'] }}</dd>
                        <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.last_beat') }}</dt>
                        <dd class="col-sm-7">
                            @if ($worker['last_beat'])
                                <x-time :value="$worker['last_beat']" /> <span class="text-secondary">({{ $worker['last_beat']->diffForHumans() }})</span>
                            @else
                                <span class="text-secondary">{{ __('workers.never') }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.current') }}</dt>
                        <dd class="col-sm-7">
                            @if ($worker['current'])
                                <code>{{ $worker['current'] }}</code> <span class="text-secondary">{{ __('workers.since', ['time' => $worker['current_since']->diffForHumans()]) }}</span>
                                @if ($worker['running'] > 1)
                                    <span class="text-secondary">{{ __('workers.more_running', ['count' => $worker['running'] - 1]) }}</span>
                                @endif
                            @else
                                <span class="text-secondary">{{ __('workers.idle') }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.waiting') }}</dt>
                        <dd class="col-sm-7">
                            {{ $worker['waiting'] }}
                            @if ($worker['oldest_waiting'])
                                <span class="text-secondary">{{ __('workers.oldest', ['time' => $worker['oldest_waiting']->diffForHumans()]) }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.failed') }}</dt>
                        <dd class="col-sm-7 mb-0">
                            @if ($worker['failed'] > 0)
                                <x-badge tone="warning">{{ $worker['failed'] }}</x-badge>
                                <span class="text-secondary">{{ __('workers.last_failed', ['time' => $worker['last_failed']?->diffForHumans()]) }}</span>
                            @else
                                0
                            @endif
                        </dd>
                    </dl>
                    <x-slot:footer>
                        <form method="POST" action="{{ route($worker['state'] === 'paused' ? 'workers.resume' : 'workers.pause') }}">
                            @csrf
                            <input type="hidden" name="queue" value="{{ $worker['name'] }}">
                            @if ($worker['state'] === 'paused')
                                <button type="submit" class="btn btn-primary"><x-icon name="player-play" class="me-1" />{{ __('workers.resume') }}</button>
                            @else
                                <button type="submit" class="btn btn-outline-warning"><x-icon name="player-pause" class="me-1" />{{ __('workers.pause') }}</button>
                            @endif
                        </form>
                    </x-slot:footer>
                </x-card>
            @endforeach

            <form method="POST" action="{{ route('workers.restart') }}">
                @csrf
                <x-card :title="__('workers.restart.title')">
                    <p class="text-secondary">{{ __('workers.restart.text') }}</p>
                    <p class="mb-0">
                        <span class="text-secondary">{{ __('workers.restart.last') }}</span>
                        @if ($lastRestart)
                            <x-time :value="$lastRestart" />
                        @else
                            <span class="text-secondary">{{ __('workers.never') }}</span>
                        @endif
                    </p>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="refresh" class="me-1" />{{ __('workers.restart.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>

            <x-card :title="__('workers.names.scheduler')" :accent="$accent($scheduler['state'])">
                <x-slot:actions>
                    <x-badge :tone="$tone($scheduler['state'])">{{ __('workers.states.'.$scheduler['state']) }}</x-badge>
                </x-slot:actions>
                <p class="text-secondary">{{ __('workers.descriptions.scheduler') }}</p>
                <dl class="row mb-0">
                    <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.unit') }}</dt>
                    <dd class="col-sm-7"><code>{{ App\Workers\PanelWorkers::SCHEDULER_UNIT }}</code></dd>
                    <dt class="col-sm-5 fw-normal text-secondary">{{ __('workers.fields.last_run') }}</dt>
                    <dd class="col-sm-7 mb-0">
                        @if ($scheduler['last_beat'])
                            <x-time :value="$scheduler['last_beat']" /> <span class="text-secondary">({{ $scheduler['last_beat']->diffForHumans() }})</span>
                        @else
                            <span class="text-secondary">{{ __('workers.never') }}</span>
                        @endif
                    </dd>
                </dl>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('workers.about.title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('workers.about.points') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

<div wire:poll.60s>
    @use('App\Support\Units')

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-server-state :server="$server" :detail="false" />
        <span class="small text-secondary">
            @if ($metric)
                {{ __('servers.load.measured', ['time' => $metric->recorded_at->locale(app()->getLocale())->diffForHumans()]) }}
            @endif
        </span>

        {{-- Time range as a segmented control, filters in one row above the charts. --}}
        <div class="btn-group ms-auto" role="group" aria-label="{{ __('servers.load.range') }}">
            @foreach ($ranges as $option)
                <button type="button" wire:click="$set('range', '{{ $option }}')"
                    @class(['btn btn-sm', 'btn-primary' => $range === $option, 'btn-outline-secondary' => $range !== $option])
                    aria-pressed="{{ $range === $option ? 'true' : 'false' }}">{{ __('servers.load.ranges.'.$option) }}</button>
            @endforeach
        </div>
    </div>

    @if (! $metric)
        <x-card>
            <p class="mb-1">{{ __('servers.load.empty') }}</p>
            <p class="text-secondary mb-0">{{ __('servers.load.empty_hint') }}</p>
        </x-card>
    @else
        <div class="row row-cards mb-3">
            <div class="col-sm-6 col-xl-3">
                <x-card class="h-100">
                    <x-meter :label="__('servers.load.cpu')" :percent="$metric->cpu_percent"
                        :detail="trans_choice('servers.load.cores', $server->cpu_cores ?? 0, ['count' => $server->cpu_cores ?? '–'])" />
                </x-card>
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-card class="h-100">
                    <x-meter :label="__('servers.load.memory')" :percent="$metric->memoryPercent()"
                        :detail="Units::bytes($metric->memory_used).' / '.Units::bytes($metric->memory_total)
                            .($metric->swap_total > 0 ? ' · '.__('servers.load.swap').' '.Units::percent($metric->swapPercent()) : '')" />
                </x-card>
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-card class="h-100">
                    <x-meter :label="__('servers.load.disk')" :percent="$metric->diskPercent()"
                        :detail="Units::bytes($metric->disk_used).' / '.Units::bytes($metric->disk_total)" />
                </x-card>
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-card class="h-100">
                    <div class="d-flex align-items-baseline mb-1">
                        <span class="subheader">{{ __('servers.load.load') }}</span>
                        <span class="ms-auto h3 mb-0">{{ Units::load($metric->load1) }}</span>
                    </div>
                    <div class="small text-secondary">
                        {{ __('servers.load.load_detail', [
                            'five' => Units::load($metric->load5),
                            'fifteen' => Units::load($metric->load15),
                        ]) }}
                    </div>
                </x-card>
            </div>
        </div>

        <div class="row row-cards">
            <div class="col-lg-6">
                <x-card class="h-100">
                    <x-line-chart :title="__('servers.load.cpu')" :times="$history['times']" :range="$range" :max="100"
                        :series="[['label' => __('servers.load.cpu'), 'values' => $history['cpu']]]" />
                </x-card>
            </div>
            <div class="col-lg-6">
                <x-card class="h-100">
                    <x-line-chart :title="__('servers.load.memory')" :times="$history['times']" :range="$range" :max="100"
                        :subtitle="Units::bytes($metric->memory_total)"
                        :series="[['label' => __('servers.load.memory'), 'values' => $history['memory']]]" />
                </x-card>
            </div>
            <div class="col-lg-6">
                <x-card class="h-100">
                    <x-line-chart :title="__('servers.load.load')" :times="$history['times']" :range="$range" format="load"
                        :floor="max(1, $server->cpu_cores ?? 1)"
                        :subtitle="trans_choice('servers.load.cores', $server->cpu_cores ?? 0, ['count' => $server->cpu_cores ?? '–'])"
                        :series="[['label' => __('servers.load.load'), 'values' => $history['load']]]" />
                </x-card>
            </div>
            <div class="col-lg-6">
                <x-card class="h-100">
                    <x-line-chart :title="__('servers.load.disk')" :times="$history['times']" :range="$range" :max="100"
                        :subtitle="Units::bytes($metric->disk_total)"
                        :series="[['label' => __('servers.load.disk'), 'values' => $history['disk']]]" />
                </x-card>
            </div>
            @if (count($history['interfaces']) > 1)
                {{-- One chart per network card, e.g. public and Hetzner private network (E-111). --}}
                @foreach ($history['interfaces'] as $card)
                    <div class="col-lg-6">
                        <x-card class="h-100">
                            <x-line-chart :title="__($card['public'] ? 'servers.load.network_public' : 'servers.load.network_private')"
                                :subtitle="$card['name']" :times="$history['times']" :range="$range" format="rate" :floor="1024"
                                :series="[
                                    ['label' => __('servers.load.received'), 'values' => $card['rx']],
                                    ['label' => __('servers.load.sent'), 'values' => $card['tx']],
                                ]" />
                        </x-card>
                    </div>
                @endforeach
            @else
                <div class="col-12">
                    <x-card>
                        <x-line-chart :title="__('servers.load.network')" :times="$history['times']" :range="$range" format="rate" :floor="1024"
                            :series="[
                                ['label' => __('servers.load.received'), 'values' => $history['rx']],
                                ['label' => __('servers.load.sent'), 'values' => $history['tx']],
                            ]" />
                    </x-card>
                </div>
            @endif
        </div>
    @endif
</div>

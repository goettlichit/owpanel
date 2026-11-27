<x-layouts.app :title="__('dns.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('dns.title')" :pretitle="$server->display_name" />

    @php
        $tones = ['ok' => 'ok', 'waiting' => 'info', 'missing' => 'warning', 'wrong' => 'danger', 'unknown' => 'neutral'];
        $accents = ['ok' => 'green', 'waiting' => 'blue', 'missing' => 'orange', 'wrong' => 'red', 'unknown' => 'blue'];
        $records = $dns['records'];
        $changeKeys = array_column($changes, 'key');
        $ptrState = collect($dns['ptr'])->pluck('state')->sortBy(fn ($state) => array_search($state, ['wrong', 'missing', 'waiting', 'ok'], true))->first() ?? 'unknown';
    @endphp

    {{-- Host name left, info box top right, reverse DNS below (E-153, docs/DESIGN.md "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <x-card :title="__('dns.server.card')" :accent="$accents[$records['state'] ?? 'unknown']">
                @if ($records)
                    <x-slot:actions>
                        <x-badge :tone="$tones[$records['state']]" class="text-uppercase">{{ __('dns.states.'.$records['state']) }}</x-badge>
                    </x-slot:actions>
                @endif
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('dns.server.host') }}</dt>
                    <dd class="col-sm-8">
                        @if ($dns['host'])
                            <code>{{ $dns['host'] }}</code>
                        @else
                            <span class="text-secondary">–</span>
                        @endif
                    </dd>
                    <dt class="col-sm-4">{{ __('dns.fields.server') }}</dt>
                    <dd class="col-sm-8">
                        @forelse (array_filter([$server->public_ipv4, $server->public_ipv6]) as $address)
                            <code>{{ $address }}</code>@if (! $loop->last)<br>@endif
                        @empty
                            <span class="text-secondary">–</span>
                        @endforelse
                    </dd>
                    @if ($records && $records['zone'])
                        <dt class="col-sm-4">{{ __('dns.fields.zone') }}</dt>
                        <dd class="col-sm-8"><code>{{ $records['zone']['name'] }}</code> <span class="text-secondary small">{{ __('dns.zone_hetzner') }}</span></dd>
                    @elseif ($records && $records['zone_error'])
                        <dt class="col-sm-4">{{ __('dns.fields.zone') }}</dt>
                        <dd class="col-sm-8 text-secondary">{{ __('dns.zone_error', ['error' => __('snapshots.errors.'.$records['zone_error'])]) }}</dd>
                    @elseif ($records && $records['hetzner'])
                        <dt class="col-sm-4">{{ __('dns.fields.zone') }}</dt>
                        <dd class="col-sm-8 text-secondary">{{ __('dns.zone_missing') }}</dd>
                    @endif
                    @if ($records)
                        <dt class="col-sm-4">{{ __('dns.fields.nameservers') }}</dt>
                        <dd class="col-sm-8 mb-0">
                            @forelse ($records['nameservers'] as $nameserver)
                                <code>{{ $nameserver['host'] }}</code>@if (! $loop->last)<br>@endif
                            @empty
                                <span class="text-secondary">{{ __('dns.no_nameservers') }}</span>
                            @endforelse
                        </dd>
                    @endif
                </dl>
                @if (! $dns['host'])
                    <p class="text-secondary mt-3 mb-0">{{ __('dns.server.no_host') }}</p>
                @elseif ($records && ! $records['zone'] && collect($records['rows'])->contains(fn ($row) => $row['state'] !== 'ok'))
                    <p class="text-secondary mt-3 mb-0">{{ __('dns.server.manual') }}</p>
                @endif
                <x-slot:footer>
                    <div class="d-flex flex-wrap gap-2 justify-content-end align-items-center">
                        @if ($changes !== [])
                            <form method="POST" action="{{ route('servers.dns.records', $server) }}" class="d-flex flex-wrap gap-2 align-items-center" data-ow-busy="{{ __('dns.change.working') }}">
                                @csrf
                                @foreach ($changeKeys as $key)
                                    <input type="hidden" name="records[]" value="{{ $key }}">
                                @endforeach
                                <span class="text-secondary small">{{ __('dns.server.set_records_hint') }}</span>
                                <select name="ttl" class="form-select w-auto" aria-label="TTL">
                                    @foreach ($ttls as $ttl)
                                        <option value="{{ $ttl }}" @selected($ttl === \App\Dns\SiteDnsWriter::DEFAULT_TTL)>{{ __('dns.ttl', ['seconds' => $ttl]) }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-outline-primary"><x-icon name="pencil" class="me-1" />{{ __('dns.server.set_records') }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('servers.dns.check', $server) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary"><x-icon name="refresh" class="me-1" />{{ __('dns.check') }}</button>
                        </form>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('dns.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('dns.server.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>

        @if ($records && $records['rows'] !== [])
            <div class="col-12">
                <x-card :title="__('dns.records')" :flush="true">
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('dns.fields.name') }}</th>
                                        <th scope="col">{{ __('dns.fields.type') }}</th>
                                        <th scope="col">{{ __('dns.fields.expected') }}</th>
                                        <th scope="col">{{ __('dns.fields.current') }}</th>
                                        <th scope="col">{{ __('dns.fields.state') }}</th>
                                        @if ($changes !== [])
                                            <th scope="col" class="w-1"><span class="visually-hidden">{{ __('dns.fields.action') }}</span></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($records['rows'] as $row)
                                        @php($key = $row['host'].'|'.$row['type'])
                                        <tr>
                                            <td><code>{{ $row['host'] }}</code></td>
                                            <td>{{ $row['type'] }}</td>
                                            <td><code>{{ $row['expected'] }}</code></td>
                                            <td>@include('dns._current', ['row' => $row, 'withZone' => (bool) $records['zone']])</td>
                                            <td><x-badge :tone="$tones[$row['state']]">{{ __('dns.states.'.$row['state']) }}</x-badge></td>
                                            @if ($changes !== [])
                                                <td class="text-end text-nowrap">
                                                    @if (in_array($key, $changeKeys, true))
                                                        <form method="POST" action="{{ route('servers.dns.records', $server) }}" data-ow-busy="{{ __('dns.change.working') }}">
                                                            @csrf
                                                            <input type="hidden" name="records[]" value="{{ $key }}">
                                                            <input type="hidden" name="ttl" value="{{ in_array($row['ttl'], $ttls, true) ? $row['ttl'] : \App\Dns\SiteDnsWriter::DEFAULT_TTL }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary">{{ $row['zone'] === [] ? __('dns.change.create') : __('dns.change.correct') }}</button>
                                                        </form>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                </x-card>
            </div>
        @endif

        <div class="col-12">
            <x-card :title="__('dns.server.ptr_card')" :flush="true" :accent="$accents[$ptrState]">
                <div class="card-body">
                    <p class="mb-1">{{ __('dns.server.ptr_intro') }}</p>
                    <p class="text-secondary small mb-0">
                        @if ($dns['ptr_error'])
                            {{ __('dns.server.ptr_error', ['error' => __('snapshots.errors.'.$dns['ptr_error'])]) }}
                        @elseif ($dns['hetzner_id'])
                            {{ __('dns.server.ptr_hetzner') }} {{ __('dns.server.ptr_hint') }}
                        @else
                            {{ __('dns.server.ptr_lookup') }}
                        @endif
                    </p>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('dns.server.address') }}</th>
                                <th scope="col">{{ __('dns.server.current') }}</th>
                                <th scope="col">{{ __('dns.fields.state') }}</th>
                                @if ($dns['hetzner_id'])
                                    <th scope="col">{{ __('dns.server.new') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dns['ptr'] as $row)
                                <tr>
                                    <td><span class="text-secondary small">{{ $row['type'] }}</span><br><code>{{ $row['ip'] }}</code></td>
                                    <td>@if ($row['current'])<code>{{ $row['current'] }}</code>@else<span class="text-secondary">{{ __('dns.none') }}</span>@endif</td>
                                    <td><x-badge :tone="$tones[$row['state']]">{{ __('dns.server.states.'.$row['state']) }}</x-badge></td>
                                    @if ($dns['hetzner_id'])
                                        <td>
                                            <form method="POST" action="{{ route('servers.dns.ptr', $server) }}" class="d-flex gap-2" novalidate data-ow-busy="{{ __('dns.server.ptr_working') }}">
                                                @csrf
                                                <input type="hidden" name="ip" value="{{ $row['ip'] }}">
                                                <input type="text" name="dns_ptr" class="form-control" style="flex: 1 1 14rem; min-width: 10rem"
                                                    value="{{ old('ip') === $row['ip'] ? old('dns_ptr') : ($row['state'] === 'ok' ? $row['current'] : ($dns['host'] ?? $row['current'])) }}"
                                                    aria-label="{{ __('dns.server.new') }} {{ $row['ip'] }}" autocomplete="off" spellcheck="false">
                                                <button type="submit" class="btn btn-outline-primary">{{ __('dns.server.save') }}</button>
                                            </form>
                                            @if (old('ip') === $row['ip'])
                                                @error('dns_ptr')
                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                @enderror
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-secondary">{{ __('dns.no_records') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
</x-layouts.app>

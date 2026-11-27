<x-layouts.app :title="__('dns.title').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('dns.title')" :pretitle="$site->display_domain" />

    @php
        $tones = ['ok' => 'ok', 'waiting' => 'info', 'missing' => 'warning', 'wrong' => 'danger', 'unknown' => 'neutral'];
        $accents = ['ok' => 'green', 'waiting' => 'blue', 'missing' => 'orange', 'wrong' => 'red', 'unknown' => 'blue'];
    @endphp

    {{-- Overview left, info box top right, records below (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        {{-- Certificate catch-up: the name servers point here now, but the website has no (complete) certificate yet. --}}
        @if (in_array($dns['state'], ['ok', 'waiting'], true) && ($site->canRequestCertificate() || $site->canRequestWwwCertificate()))
            @can('requestCertificate', $site)
                <div class="col-12">
                    <x-card :title="__('dns.certificate.title')" accent="orange">
                        <p class="mb-0">{{ __('dns.certificate.text') }}</p>
                        <x-slot:footer>
                            <form data-ow-busy="{{ __('tasks.busy.certificate') }}" data-ow-task="site-create gateway-site" method="POST" action="{{ route('sites.certificate', $site) }}" class="text-end">
                                @csrf
                                <button type="submit" class="btn btn-primary"><x-icon name="certificate" class="me-1" />{{ __('dns.certificate.request') }}</button>
                            </form>
                        </x-slot:footer>
                    </x-card>
                </div>
            @endcan
        @endif
        <div class="col-lg-8">
            <x-card :title="__('dns.card')" :accent="$accents[$dns['state']]">
                <x-slot:actions>
                    <x-badge :tone="$tones[$dns['state']]" class="text-uppercase">{{ __('dns.states.'.$dns['state']) }}</x-badge>
                </x-slot:actions>
                <p>{{ __('dns.overall.'.$dns['state']) }}</p>
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('dns.fields.ingress') }}</dt>
                    <dd class="col-sm-8">
                        @forelse (\App\Gateway\Ingress::addresses() as $address)
                            <code>{{ $address }}</code>@if (! $loop->last)<br>@endif
                        @empty
                            <span class="text-secondary">–</span>
                        @endforelse
                    </dd>
                    @if ($dns['zone'])
                        <dt class="col-sm-4">{{ __('dns.fields.zone') }}</dt>
                        <dd class="col-sm-8"><code>{{ $dns['zone']['name'] }}</code> <span class="text-secondary small">{{ __('dns.zone_hetzner') }}</span></dd>
                    @elseif ($dns['zone_error'])
                        <dt class="col-sm-4">{{ __('dns.fields.zone') }}</dt>
                        <dd class="col-sm-8 text-secondary">{{ __('dns.zone_error', ['error' => __('snapshots.errors.'.$dns['zone_error'])]) }}</dd>
                    @elseif ($dns['hetzner'])
                        {{-- The token sees only the zones of its own project (E-197). --}}
                        <dt class="col-sm-4">{{ __('dns.fields.zone') }}</dt>
                        <dd class="col-sm-8 text-secondary">{{ __('dns.zone_missing') }}</dd>
                    @endif
                    <dt class="col-sm-4">{{ __('dns.fields.nameservers') }}</dt>
                    <dd class="col-sm-8 mb-0">
                        @forelse ($dns['nameservers'] as $server)
                            <code>{{ $server['host'] }}</code>@if (! $loop->last)<br>@endif
                        @empty
                            <span class="text-secondary">{{ __('dns.no_nameservers') }}</span>
                        @endforelse
                    </dd>
                </dl>
                <x-slot:footer>
                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                        @if ($changes !== [])
                            <a href="{{ route('sites.dns.change', $site) }}" class="btn btn-outline-primary"><x-icon name="pencil" class="me-1" />{{ __('dns.change.all', ['count' => count($changes)]) }}</a>
                        @endif
                        <form method="POST" action="{{ route('sites.dns.check', $site) }}">
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
                    @foreach (__('dns.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('dns.records')" :flush="true">
                @if ($dns['rows'] === [])
                    <div class="card-body text-secondary">{{ __('dns.no_records') }}</div>
                @else
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
                                @foreach ($dns['rows'] as $row)
                                    <tr>
                                        <td><code>{{ $row['host'] }}</code></td>
                                        <td>{{ $row['type'] }}</td>
                                        <td><code>{{ $row['expected'] }}</code></td>
                                        <td>@include('dns._current', ['row' => $row, 'withZone' => (bool) $dns['zone']])</td>
                                        <td><x-badge :tone="$tones[$row['state']]">{{ __('dns.states.'.$row['state']) }}</x-badge></td>
                                        @if ($changes !== [])
                                            <td class="text-end text-nowrap">
                                                @isset ($changes[$row['host'].'|'.$row['type']])
                                                    <a href="{{ route('sites.dns.change', [$site, 'records' => [$row['host'].'|'.$row['type']]]) }}" class="btn btn-sm btn-outline-primary">
                                                        {{ $row['zone'] === [] ? __('dns.change.create') : __('dns.change.correct') }}
                                                    </a>
                                                @endisset
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>

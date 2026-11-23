{{--
    DNS check of a website in the customer area (E-124, E-143): only reads.
    Shows the addresses the website needs and what the name servers answer;
    no Hetzner zone and no changes. "Sie" (E-132).
--}}
<x-layouts.app :title="__('dns.title').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('dns.title')" :pretitle="$site->display_domain" />

    @php
        $tones = ['ok' => 'ok', 'waiting' => 'info', 'missing' => 'warning', 'wrong' => 'danger', 'unknown' => 'neutral'];
        $accents = ['ok' => 'green', 'waiting' => 'blue', 'missing' => 'orange', 'wrong' => 'red', 'unknown' => 'blue'];
        $list = fn (?array $values) => $values === null ? null : ($values === [] ? __('dns.none') : implode(', ', $values));
    @endphp

    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <x-card :title="__('dns.card')" :accent="$accents[$dns['state']]">
                <x-slot:actions>
                    <x-badge :tone="$tones[$dns['state']]" class="text-uppercase">{{ __('dns.states.'.$dns['state']) }}</x-badge>
                </x-slot:actions>
                <p class="mb-0">{{ __('my.dns.overall.'.$dns['state']) }}</p>
                <x-slot:footer>
                    <form method="POST" action="{{ route('my.sites.dns.check', $site) }}" class="text-end">
                        @csrf
                        <button type="submit" class="btn btn-primary"><x-icon name="refresh" class="me-1" />{{ __('dns.check') }}</button>
                    </form>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('dns.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('my.dns.about') as $point)
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
                                    <th scope="col">{{ __('dns.fields.authoritative') }}</th>
                                    <th scope="col">{{ __('dns.fields.resolver') }}</th>
                                    <th scope="col">{{ __('dns.fields.state') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dns['rows'] as $row)
                                    <tr>
                                        <td><code>{{ $row['host'] }}</code></td>
                                        <td>{{ $row['type'] }}</td>
                                        <td><code>{{ $row['expected'] }}</code><x-copy-button :value="$row['expected']" /></td>
                                        <td><span @class(['text-secondary' => ! $row['authoritative']])>{{ $list($row['authoritative']) ?? __('dns.no_answer') }}</span></td>
                                        <td><span @class(['text-secondary' => $row['resolver'] === []])>{{ $list($row['resolver']) }}</span></td>
                                        <td><x-badge :tone="$tones[$row['state']]">{{ __('dns.states.'.$row['state']) }}</x-badge></td>
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

{{-- Blocklists of a server with hits, own addresses and the address check (E-170), loaded after the page (BlocklistDetails). --}}
<div>
    @if ($blocklists->isEmpty())
        <div class="card-body text-secondary">{{ __('firewall.blocklists.empty') }}</div>
    @else
        {{-- Own addresses on a list (E-170). --}}
        @if ($blocklistOwn !== [])
            <div class="card-body border-bottom">
                <div class="alert alert-warning mb-0" role="alert">
                    <div class="alert-title"><x-icon name="alert-triangle" class="me-1" />{{ __('firewall.blocklists.own.title') }}</div>
                    <ul class="mb-1 ps-3">
                        @foreach ($blocklistOwn as $own)
                            <li>
                                <code>{{ $own['address'] }}</code>
                                ({{ trim(__('firewall.blocklists.own.kinds.'.$own['kind'], ['name' => $own['name'] ?? ''])) }}):
                                {{ implode(', ', $own['lists']) }}
                                –
                                {{ $own['excepted'] ? __('firewall.blocklists.own.excepted') : __('firewall.blocklists.own.blocked') }}
                            </li>
                        @endforeach
                    </ul>
                    <div class="small">{{ __('firewall.blocklists.own.hint') }}</div>
                </div>
            </div>
        @endif
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('firewall.blocklists.name') }}</th>
                        <th scope="col">{{ __('firewall.blocklists.entries') }}</th>
                        <th scope="col">{{ __('firewall.blocklists.hits.title') }}</th>
                        <th scope="col">{{ __('firewall.blocklists.checked') }}</th>
                        <th scope="col">{{ __('firewall.blocklists.settings') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('firewall.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blocklists as $list)
                        <tr>
                            <td class="text-break">
                                @if ($list->name !== null)
                                    <div class="fw-medium">{{ $list->name }}</div>
                                @endif
                                <code class="small" title="{{ $list->url }}">{{ \Illuminate\Support\Str::limit($list->shortUrl(), 60) }}</code>
                                <div class="small">
                                    @if ($list->error)
                                        <span class="text-red" title="{{ __('firewall.blocklists.state_failed_hint') }}"><x-icon name="alert-triangle" class="me-1" />{{ __('firewall.blocklists.state_failed', ['reason' => $list->errorText()]) }}</span>
                                    @else
                                        <span class="text-green"><x-icon name="circle-check" class="me-1" />{{ __('firewall.blocklists.state_ok') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-nowrap">
                                {{ \Illuminate\Support\Number::format($list->entries, locale: app()->getLocale()) }}
                                @if ($list->ignored > 0)
                                    <div class="small text-secondary" title="{{ __('firewall.blocklists.ignored_hint') }}">{{ __('firewall.blocklists.ignored', ['count' => \Illuminate\Support\Number::format($list->ignored, locale: app()->getLocale())]) }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                @php($listHits = $blocklistHits['per_list'][(string) $list->getKey()] ?? null)
                                @if ($listHits !== null && $listHits['packets'] > 0)
                                    {{ \Illuminate\Support\Number::format($listHits['packets'], locale: app()->getLocale()) }}
                                    <div class="small text-secondary">{{ trans_choice('firewall.blocklists.hits.addresses', $listHits['addresses'], ['count' => \Illuminate\Support\Number::format($listHits['addresses'], locale: app()->getLocale())]) }}</div>
                                @else
                                    <span class="text-secondary">0</span>
                                @endif
                            </td>
                            <td class="text-nowrap small">
                                {{ $list->checked_at ? $list->checked_at->locale(app()->getLocale())->diffForHumans() : __('firewall.blocklists.never') }}
                                @if ($list->changed_at)
                                    <div class="text-secondary">{{ __('firewall.blocklists.changed') }}: {{ $list->changed_at->locale(app()->getLocale())->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('servers.firewall.blocklists.update', [$server, $list]) }}" class="d-flex flex-wrap gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="text" name="name" value="{{ $list->name }}" maxlength="60" class="form-control form-control-sm w-100"
                                        placeholder="{{ __('firewall.blocklists.name_placeholder') }}" aria-label="{{ __('firewall.blocklists.name') }}">
                                    <select name="interval_hours" class="form-select form-select-sm w-auto flex-grow-1" aria-label="{{ __('firewall.blocklists.interval') }}">
                                        @foreach (\App\Models\Blocklist::INTERVALS as $hours)
                                            <option value="{{ $hours }}" @selected($list->interval_hours === $hours)>{{ __('firewall.blocklists.intervals.'.$hours) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('firewall.blocklists.save') }}</button>
                                </form>
                            </td>
                            <td class="text-end text-nowrap">
                                <form data-ow-busy="{{ __('tasks.busy.blocklist') }}" method="POST" action="{{ route('servers.firewall.blocklists.refresh', [$server, $list]) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="{{ __('firewall.blocklists.refresh') }}" title="{{ __('firewall.blocklists.refresh') }}">
                                        <x-icon name="refresh" />
                                    </button>
                                </form>
                                <form data-ow-busy="{{ __('tasks.busy.firewall') }}" method="POST" action="{{ route('servers.firewall.blocklists.destroy', [$server, $list]) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('firewall.blocklists.delete') }}" title="{{ __('firewall.blocklists.delete') }}">
                                        <x-icon name="trash" />
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Hits of the blocklists (E-170): read from the server, not stored. --}}
        <div class="card-body border-top">
            <h4 class="mb-2">{{ __('firewall.blocklists.hits.top') }}</h4>
            @if ($blocklistHits['error'] !== null)
                <div class="text-danger">{{ __('firewall.blocklists.hits.error', ['error' => __('tasks.errors.'.$blocklistHits['error']) !== 'tasks.errors.'.$blocklistHits['error'] ? __('tasks.errors.'.$blocklistHits['error']) : $blocklistHits['error']]) }}</div>
            @elseif (! $blocklistHits['counting'] && $blocklistHits['retried'])
                <div class="text-warning">{{ __('firewall.blocklists.hits.unavailable') }}</div>
            @elseif (! $blocklistHits['counting'])
                <div class="text-secondary">{{ __('firewall.blocklists.hits.setup') }}</div>
            @elseif ($blocklistHits['addresses'] === 0)
                <div class="text-secondary">{{ __('firewall.blocklists.hits.none') }}</div>
            @else
                <div class="text-secondary mb-2">
                    {{ __('firewall.blocklists.hits.summary', [
                        'addresses' => \Illuminate\Support\Number::format($blocklistHits['addresses'], locale: app()->getLocale()),
                        'packets' => \Illuminate\Support\Number::format($blocklistHits['packets'], locale: app()->getLocale()),
                        'bytes' => \App\Support\Units::bytes($blocklistHits['bytes']),
                    ]) }}
                    {{ __('firewall.blocklists.hits.window') }}
                    @if ($blocklistHits['partial'])
                        {{ __('firewall.blocklists.hits.partial') }}
                    @endif
                </div>
            @endif
        </div>
        @if ($blocklistHits['error'] === null && $blocklistHits['top'] !== [])
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('firewall.blocklists.hits.address') }}</th>
                            <th scope="col">{{ __('firewall.blocklists.hits.packets') }}</th>
                            <th scope="col">{{ __('firewall.blocklists.hits.bytes') }}</th>
                            <th scope="col">{{ __('firewall.blocklists.hits.seen') }}</th>
                            <th scope="col">{{ __('firewall.blocklists.hits.lists') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($blocklistHits['top'] as $hit)
                            <tr>
                                <td><code>{{ $hit['ip'] }}</code></td>
                                <td>{{ \Illuminate\Support\Number::format($hit['packets'], locale: app()->getLocale()) }}</td>
                                <td class="text-nowrap">{{ \App\Support\Units::bytes($hit['bytes']) }}</td>
                                <td class="text-nowrap small">{{ now()->subSeconds($hit['seen'])->locale(app()->getLocale())->diffForHumans() }}</td>
                                <td class="small">{{ $hit['lists'] === [] ? __('firewall.blocklists.hits.no_list') : implode(', ', $hit['lists']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Check one address against the lists; only reads, nothing is stored. --}}
        <div class="card-body border-top" id="blocklist-check">
            <h4 class="mb-2">{{ __('firewall.blocklists.check.title') }}</h4>
            <form method="GET" action="{{ route('servers.firewall', $server) }}#blocklist-check" class="row g-2 align-items-start">
                <div class="col-md-5">
                    <input type="text" name="check" value="{{ $blocklistCheck['input'] ?? '' }}" maxlength="60" autocomplete="off"
                        placeholder="{{ __('firewall.blocklists.check.placeholder') }}" aria-label="{{ __('firewall.blocklists.check.address') }}"
                        @class(['form-control', 'is-invalid' => $blocklistCheck !== null && $blocklistCheck['address'] === null])>
                    @if ($blocklistCheck !== null && $blocklistCheck['address'] === null)
                        <div class="invalid-feedback">{{ __('firewall.blocklists.check.invalid') }}</div>
                    @endif
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-outline-secondary text-nowrap"><x-icon name="search" class="me-1" />{{ __('firewall.blocklists.check.submit') }}</button>
                </div>
            </form>
            @if ($blocklistCheck !== null && $blocklistCheck['address'] !== null)
                <div class="mt-2">
                    @if ($blocklistCheck['lists'] === [])
                        <span class="text-green"><x-icon name="circle-check" class="me-1" />{{ __('firewall.blocklists.check.none', ['address' => $blocklistCheck['address']]) }}</span>
                    @else
                        <span class="text-warning"><x-icon name="alert-triangle" class="me-1" />{{ __('firewall.blocklists.check.found', ['address' => $blocklistCheck['address'], 'lists' => implode(', ', $blocklistCheck['lists'])]) }}</span>
                        <div class="small text-secondary">{{ $blocklistCheck['excepted'] ? __('firewall.blocklists.own.excepted') : __('firewall.blocklists.own.blocked') }}</div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>

<x-layouts.app :title="__('firewall.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('firewall.title')" :pretitle="$server->display_name" />

    @php
        $protocolLabel = fn (string $protocol) => __('firewall.protocols.'.$protocol);
        $rolledBack = $rulesTask?->status->value === 'succeeded'
            && ($rulesTask->result['firewall_confirm_required'] ?? false) === true
            && $confirmTask?->status->value === 'failed'
            && $confirmTask->created_at >= $rulesTask->created_at;
    @endphp

    <div class="row row-cards">
        @if ($rolledBack || $rulesTask?->status->value === 'failed')
            <div class="col-12">
                <x-card :title="__('firewall.state.failed_title')" accent="red">
                    <p class="mb-0">{{ __($rolledBack ? 'firewall.state.rolled_back' : 'firewall.state.failed') }}</p>
                </x-card>
            </div>
        @endif

        {{-- Own rules --}}
        <div class="col-12">
            <x-card :title="__('firewall.rules.title')" :flush="true">
                <x-slot:actions>
                    <div class="d-flex flex-wrap gap-3">
                        @if ($rulesTask?->status === \App\Enums\TaskStatus::Failed)
                            <livewire:task-badge :task="$rulesTask" :label="__('firewall.sync.rules')" :key="'r'.$rulesTask->id" />
                        @endif
                        @if ($confirmTask?->status === \App\Enums\TaskStatus::Failed && $rulesTask && $confirmTask->created_at >= $rulesTask->created_at)
                            <livewire:task-badge :task="$confirmTask" :label="__('firewall.sync.confirm')" :key="'c'.$confirmTask->id" />
                        @endif
                    </div>
                </x-slot:actions>

                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('firewall.rules.port') }}</th>
                                <th scope="col">{{ __('firewall.rules.protocol') }}</th>
                                <th scope="col">{{ __('firewall.rules.source') }}</th>
                                <th scope="col">{{ __('firewall.rules.comment') }}</th>
                                <th scope="col"><span class="visually-hidden">{{ __('firewall.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Fixed ports, always open so nobody locks himself out. A
                                 website server takes the web only from the private network,
                                 where the gateway is (E-196, E-197). --}}
                            @php($fixed = $server->hostsPanel()
                                ? [[$server->port, 'tcp', 'ssh', null], ['80', 'tcp', 'http', null], ['443', 'tcp', 'https', null], ['443', 'udp', 'http3', null]]
                                : [[$server->port, 'tcp', 'ssh', null], [__('firewall.fixed.all_ports'), 'any', 'private', __('firewall.fixed.private_network')]])
                            @foreach ($fixed as [$port, $protocol, $purpose, $source])
                                <tr class="text-secondary">
                                    <td><code>{{ $port }}</code></td>
                                    <td>{{ $protocol === 'any' ? __('firewall.fixed.any_protocol') : $protocolLabel($protocol) }}</td>
                                    <td>{{ $source ?? __('firewall.rules.anyone') }}</td>
                                    <td>{{ __('firewall.fixed.'.$purpose) }}</td>
                                    <td class="text-end"><span class="badge bg-secondary-lt" title="{{ __('firewall.fixed.hint') }}"><x-icon name="lock" class="me-1" />{{ __('firewall.fixed.label') }}</span></td>
                                </tr>
                            @endforeach
                            @foreach ($rules as $rule)
                                <tr>
                                    <td><code>{{ $rule->ports }}</code></td>
                                    <td>{{ $protocolLabel($rule->protocol) }}</td>
                                    <td>@if ($rule->source)<code>{{ $rule->source }}</code>@else{{ __('firewall.rules.anyone') }}@endif</td>
                                    <td>{{ $rule->comment }}</td>
                                    <td class="text-end">
                                        <form data-ow-busy="{{ __('tasks.busy.firewall') }}" data-ow-task="firewall-rules" method="POST" action="{{ route('servers.firewall.rules.destroy', [$server, $rule]) }}" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('firewall.rules.delete') }}" title="{{ __('firewall.rules.delete') }}">
                                                <x-icon name="trash" />
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-slot:footer>
                    <form data-ow-busy="{{ __('tasks.busy.firewall') }}" data-ow-task="firewall-rules" method="POST" action="{{ route('servers.firewall.rules.store', $server) }}" novalidate>
                        @csrf
                        <div class="row g-2 align-items-start">
                            <div class="col-md-2">
                                <label class="form-label required" for="ports">{{ __('firewall.rules.port') }}</label>
                                <input type="text" id="ports" name="ports" value="{{ old('ports') }}" placeholder="3306" inputmode="numeric"
                                    @class(['form-control', 'is-invalid' => $errors->has('ports')])>
                                @error('ports')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="protocol">{{ __('firewall.rules.protocol') }}</label>
                                <select id="protocol" name="protocol" class="form-select">
                                    @foreach (\App\Models\FirewallRule::PROTOCOLS as $protocol)
                                        <option value="{{ $protocol }}" @selected(old('protocol', 'tcp') === $protocol)>{{ $protocolLabel($protocol) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="source">{{ __('firewall.rules.source') }}</label>
                                <input type="text" id="source" name="source" value="{{ old('source') }}" placeholder="{{ __('firewall.rules.source_placeholder') }}"
                                    @class(['form-control', 'is-invalid' => $errors->has('source')])>
                                @error('source')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="comment">{{ __('firewall.rules.comment') }}</label>
                                <input type="text" id="comment" name="comment" value="{{ old('comment') }}" maxlength="100" placeholder="{{ __('firewall.rules.comment_placeholder') }}" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100"><x-icon name="plus" class="me-1" />{{ __('firewall.rules.submit') }}</button>
                            </div>
                        </div>
                        <div class="form-hint mt-2">{{ __('firewall.rules.hint') }}</div>
                    </form>
                </x-slot:footer>
            </x-card>
        </div>

        {{-- fail2ban: current bans; read from the server after the page has loaded. --}}
        <div class="col-lg-6">
            <livewire:firewall-bans :server="$server" :client-ip="$clientIp" />
        </div>

        {{-- fail2ban: exceptions --}}
        <div class="col-lg-6">
            <x-card :title="__('firewall.ignore.title')" :flush="true" class="h-100">
                @if ($fail2banTask?->status === \App\Enums\TaskStatus::Failed)
                    <x-slot:actions>
                        <livewire:task-badge :task="$fail2banTask" :label="__('firewall.sync.fail2ban')" :key="'f'.$fail2banTask->id" />
                    </x-slot:actions>
                @endif

                @if ($ignores->isEmpty())
                    <div class="card-body text-secondary">{{ __('firewall.ignore.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <tbody>
                                @foreach ($ignores as $ignore)
                                    <tr>
                                        <td>
                                            <code>{{ $ignore->address }}</code>
                                            @if ($clientIp && \App\Support\IpNetwork::contains($ignore->address, $clientIp))
                                                <span class="badge bg-green-lt ms-1">{{ __('firewall.ignore.you') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-secondary">{{ $ignore->comment }}</td>
                                        <td class="text-end">
                                            <form data-ow-busy="{{ __('tasks.busy.ignores') }}" data-ow-task="server-fail2ban" method="POST" action="{{ route('servers.firewall.ignores.destroy', [$server, $ignore]) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('firewall.ignore.delete') }}" title="{{ __('firewall.ignore.delete') }}">
                                                    <x-icon name="trash" />
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <x-slot:footer>
                    @if ($clientIp && ! $clientIgnored)
                        <form data-ow-busy="{{ __('tasks.busy.ignores') }}" data-ow-task="server-fail2ban" method="POST" action="{{ route('servers.firewall.ignores.store', $server) }}" class="mb-3">
                            @csrf
                            <input type="hidden" name="address" value="{{ $clientIp }}">
                            <input type="hidden" name="comment" value="{{ __('firewall.ignore.mine_comment') }}">
                            <button type="submit" class="btn btn-outline-primary">
                                <x-icon name="user-check" class="me-1" />{{ __('firewall.ignore.mine', ['ip' => $clientIp]) }}
                            </button>
                        </form>
                    @endif
                    <form data-ow-busy="{{ __('tasks.busy.ignores') }}" data-ow-task="server-fail2ban" method="POST" action="{{ route('servers.firewall.ignores.store', $server) }}" novalidate>
                        @csrf
                        <div class="row g-2 align-items-start">
                            <div class="col-sm-5">
                                <label class="form-label required" for="address">{{ __('firewall.ignore.address') }}</label>
                                <input type="text" id="address" name="address" value="{{ old('address') }}" placeholder="203.0.113.0/24"
                                    @class(['form-control', 'is-invalid' => $errors->has('address')])>
                                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="ignore_comment">{{ __('firewall.rules.comment') }}</label>
                                <input type="text" id="ignore_comment" name="comment" maxlength="100" class="form-control" placeholder="{{ __('firewall.ignore.comment_placeholder') }}">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label d-none d-sm-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100"><x-icon name="plus" class="me-1" />{{ __('firewall.ignore.submit') }}</button>
                            </div>
                        </div>
                        <div class="form-hint mt-2">{{ __('firewall.ignore.hint') }}</div>
                    </form>
                </x-slot:footer>
            </x-card>
        </div>

        {{-- Blocklists (E-170) --}}
        <div class="col-12">
            <x-card :title="__('firewall.blocklists.title')" :flush="true">
                <x-slot:actions>
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                        @if ($blocklists->isNotEmpty())
                            <span class="badge bg-secondary-lt">{{ __('firewall.blocklists.total', ['count' => \Illuminate\Support\Number::format(min($blocklists->sum('entries'), \App\Models\Blocklist::MAX_ENTRIES_PER_SERVER), locale: app()->getLocale())]) }}</span>
                        @endif
                        @if ($blocklistTask?->status === \App\Enums\TaskStatus::Failed)
                            <livewire:task-badge :task="$blocklistTask" :label="__('firewall.sync.blocklists')" :key="'b'.$blocklistTask->id" />
                        @endif
                    </div>
                </x-slot:actions>

                @if ($blocklists->isEmpty())
                    <div class="card-body text-secondary">{{ __('firewall.blocklists.empty') }}</div>
                @else
                    {{-- Lists, hits and the address check; read from the server after the page has loaded. --}}
                    <livewire:blocklist-details :server="$server" :client-ip="$clientIp" :check="request()->query('check')" />
                @endif

                <x-slot:footer>
                    <form data-ow-busy="{{ __('tasks.busy.blocklist') }}" method="POST" action="{{ route('servers.firewall.blocklists.store', $server) }}" novalidate>
                        @csrf
                        <div class="row g-2 align-items-start">
                            <div class="col-md-3">
                                <label class="form-label" for="blocklist_name">{{ __('firewall.blocklists.name') }}</label>
                                <input type="text" id="blocklist_name" name="name" value="{{ old('name') }}" maxlength="60" placeholder="{{ __('firewall.blocklists.name_placeholder') }}"
                                    @class(['form-control', 'is-invalid' => $errors->has('name')])>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md">
                                <label class="form-label required" for="blocklist_url">{{ __('firewall.blocklists.url') }}</label>
                                <input type="url" id="blocklist_url" name="url" value="{{ old('url') }}" maxlength="500" placeholder="{{ __('firewall.blocklists.url_placeholder') }}"
                                    @class(['form-control', 'is-invalid' => $errors->has('url')])>
                                @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-auto">
                                <label class="form-label" for="blocklist_interval">{{ __('firewall.blocklists.interval') }}</label>
                                <select id="blocklist_interval" name="interval_hours" class="form-select">
                                    @foreach (\App\Models\Blocklist::INTERVALS as $hours)
                                        <option value="{{ $hours }}" @selected((int) old('interval_hours', 6) === $hours)>{{ __('firewall.blocklists.intervals.'.$hours) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-auto">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary text-nowrap"><x-icon name="plus" class="me-1" />{{ __('firewall.blocklists.submit') }}</button>
                            </div>
                        </div>
                        <div class="form-hint mt-2">{{ __('firewall.blocklists.intro') }} {{ __('firewall.blocklists.hint') }}</div>
                    </form>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</x-layouts.app>

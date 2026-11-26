<x-layouts.app :title="__('sites.sftp.title').' · '.$site->display_domain">
    {{-- Shared by the administration ($area "sites.") and the customer area ("my.sites.", E-143). --}}
    <x-slot:sidebar>
        @include($area === 'sites.' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.sftp.title')" :pretitle="$site->display_domain" />

    @php
        $hasPassword = $site->sftp_password !== null;
        $noLogin = $site->sftp_enabled && ! $hasPassword && $keys->isEmpty();
    @endphp

    {{-- Access and password on the left, info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        @if ($sshBlocked ?? false)
            <div class="col-12">
                <x-card :title="__('ssh.sftp_title')" accent="orange">
                    <p class="mb-0">{{ __('ssh.sftp_blocked') }}
                        <a href="{{ route('servers.ssh', $site->server) }}" class="ms-1">{{ __('servers.menu.ssh') }}</a></p>
                </x-card>
            </div>
        @endif

        @if ($noLogin)
            <div class="col-12">
                <x-card :title="__('sites.sftp.state.no_login_title')" accent="orange">
                    <p class="mb-0">{{ __('sites.sftp.state.no_login') }}</p>
                </x-card>
            </div>
        @endif

        <div class="col-lg-8 d-flex flex-column gap-3">
            <x-card :title="__('sites.sftp.access')" :accent="$site->sftp_enabled ? 'green' : 'blue'">
                <x-slot:actions>
                    @if ($site->sftp_enabled)
                        <span class="badge bg-green-lt"><x-icon name="lock-open" class="me-1" />{{ __('sites.sftp.on') }}</span>
                    @else
                        <span class="badge bg-secondary-lt"><x-icon name="lock" class="me-1" />{{ __('sites.sftp.off') }}</span>
                    @endif
                </x-slot:actions>

                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('sites.sftp.fields.host') }}</dt>
                    <dd class="col-sm-8">
                        {{-- The domain once its DNS points here; until then, e.g. while a website is
                             moved to this server, the server address that works at once. --}}
                        @if (! $domainReaches && $site->server->hostname)
                            {{-- Behind the gateway: the host name of the website's server (E-166). --}}
                            <code>{{ $site->server->hostname }}</code><x-copy-button :value="$site->server->hostname" />
                            @if ($site->server->public_ipv4)
                                <div class="small text-secondary">{{ __('sites.sftp.fields.host_alt', ['address' => $site->server->public_ipv4]) }}</div>
                            @endif
                        @elseif ($domainHere)
                            <code>{{ $site->domain }}</code><x-copy-button :value="$site->domain" />
                            @if ($site->server->public_ipv4)
                                <div class="small text-secondary">{{ __('sites.sftp.fields.host_alt', ['address' => $site->server->public_ipv4]) }}</div>
                            @endif
                        @else
                            <code>{{ $site->server->public_ipv4 }}</code><x-copy-button :value="$site->server->public_ipv4" />
                            @if ($site->server->public_ipv6)
                                <div class="small text-secondary">{{ __('sites.sftp.fields.host_ipv6') }} <code>{{ $site->server->public_ipv6 }}</code></div>
                            @endif
                            @if ($domainReaches)
                                <div class="small text-secondary">{{ __('sites.sftp.fields.host_domain', ['domain' => $site->domain]) }}</div>
                            @endif
                        @endif
                    </dd>

                    <dt class="col-sm-4">{{ __('sites.sftp.fields.port') }}</dt>
                    <dd class="col-sm-8"><code>{{ $site->server->port }}</code></dd>

                    <dt class="col-sm-4">{{ __('sites.sftp.fields.user') }}</dt>
                    <dd class="col-sm-8"><code>{{ $site->system_user }}</code><x-copy-button :value="$site->system_user" /></dd>

                    <dt class="col-sm-4">{{ __('sites.sftp.fields.directory') }}</dt>
                    <dd class="col-sm-8"><code>/htdocs</code> <span class="small text-secondary">{{ __('sites.sftp.fields.directory_hint') }}</span></dd>

                    <dt class="col-sm-4">{{ __('sites.sftp.fields.password') }}</dt>
                    <dd class="col-sm-8 mb-0">
                        @if (! $hasPassword)
                            <span class="text-secondary">{{ __('sites.sftp.no_password') }}</span>
                        @elseif ($revealed)
                            <code class="text-break">{{ $site->sftp_password }}</code><x-copy-button :value="$site->sftp_password" />
                            <div class="small text-secondary mt-1">{{ __('sites.sftp.revealed_hint') }}</div>
                        @else
                            <code>••••••••••••</code>
                            <a href="{{ route($area.'sftp.reveal', $site) }}" class="btn btn-sm btn-outline-secondary ms-2">
                                <x-icon name="eye" class="me-1" />{{ __('sites.sftp.reveal') }}
                            </a>
                        @endif
                    </dd>
                </dl>

                <x-slot:footer>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <form data-ow-busy="{{ __('tasks.busy.sftp') }}" data-ow-task="site-sftp" method="POST" action="{{ route($area.'sftp.toggle', $site) }}">
                            @csrf
                            @if ($site->sftp_enabled)
                                <button type="submit" class="btn btn-outline-secondary"><x-icon name="lock" class="me-1" />{{ __('sites.sftp.disable') }}</button>
                            @else
                                <button type="submit" class="btn btn-primary"><x-icon name="lock-open" class="me-1" />{{ __('sites.sftp.enable') }}</button>
                            @endif
                        </form>
                        {{-- How the last transfer to the server went. --}}
                        @if ($sync?->status === \App\Enums\TaskStatus::Failed)
                            <div class="ms-auto">
                                <livewire:task-badge :task="$sync" :label="__('sites.sftp.sync')" :key="$sync->id" />
                            </div>
                        @endif
                    </div>
                </x-slot:footer>
            </x-card>

            <x-card :title="__('sites.sftp.password.title')" tone="warning">
                <p class="text-secondary mb-0">{{ __($hasPassword ? 'sites.sftp.password.text_set' : 'sites.sftp.password.text_none') }}</p>
                <x-slot:footer>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route($area.'sftp.password', $site) }}">
                            @csrf
                            <input type="hidden" name="action" value="generate">
                            <button type="submit" class="btn btn-warning">
                                <x-icon name="key" class="me-1" />{{ __($hasPassword ? 'sites.sftp.password.renew' : 'sites.sftp.password.generate') }}
                            </button>
                        </form>
                        @if ($hasPassword)
                            <form method="POST" action="{{ route($area.'sftp.password', $site) }}">
                                @csrf
                                <input type="hidden" name="action" value="remove">
                                <button type="submit" class="btn btn-outline-secondary">{{ __('sites.sftp.password.remove') }}</button>
                            </form>
                        @endif
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('sites.sftp.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('sites.sftp.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
                @if (auth()->user()->isAdmin())
                    {{-- Only a hint: the website user keeps SFTP only (E-089); root switches to it. --}}
                    @php($shell = 'runuser -l '.$site->system_user.' -s /bin/bash')
                    <div class="border-top mt-3 pt-3">
                        <div class="fw-medium mb-1">{{ __('sites.sftp.shell.title') }}</div>
                        <p class="text-secondary small mb-2">{{ __('sites.sftp.shell.text') }}</p>
                        <code class="text-break">{{ $shell }}</code><x-copy-button :value="$shell" />
                    </div>
                @endif
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('sites.sftp.keys.title')" :flush="true">
                @if ($keys->isEmpty())
                    <div class="card-body text-secondary">{{ __('sites.sftp.keys.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sites.sftp.keys.name') }}</th>
                                    <th scope="col">{{ __('sites.sftp.keys.fingerprint') }}</th>
                                    <th scope="col">{{ __('sites.sftp.keys.added') }}</th>
                                    <th scope="col"><span class="visually-hidden">{{ __('sites.sftp.keys.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($keys as $key)
                                    <tr>
                                        <td>
                                            <div class="fw-medium">{{ $key->name }}</div>
                                            <div class="small text-secondary">{{ strtok($key->public_key, ' ') }}</div>
                                        </td>
                                        <td><code class="text-break">{{ $key->fingerprint }}</code></td>
                                        <td class="text-nowrap"><x-time :value="$key->created_at" /></td>
                                        <td class="text-end">
                                            <form data-ow-busy="{{ __('tasks.busy.sftp') }}" data-ow-task="site-sftp" method="POST" action="{{ route($area.'sftp.keys.destroy', [$site, $key]) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('sites.sftp.keys.delete') }}" title="{{ __('sites.sftp.keys.delete') }}">
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
            </x-card>
        </div>

        <div class="col-12">
            <form data-ow-busy="{{ __('tasks.busy.sftp') }}" data-ow-task="site-sftp" method="POST" action="{{ route($area.'sftp.keys.store', $site) }}" novalidate>
                @csrf
                <x-card :title="__('sites.sftp.keys.create')">
                    <div class="mb-3">
                        <label class="form-label required" for="public_key">{{ __('sites.sftp.keys.public_key') }}</label>
                        <textarea id="public_key" name="public_key" rows="3" required spellcheck="false" placeholder="ssh-ed25519 AAAAC3Nza… name@laptop"
                            @class(['form-control font-monospace', 'is-invalid' => $errors->has('public_key')])>{{ old('public_key') }}</textarea>
                        @error('public_key')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-hint">{{ __('sites.sftp.keys.public_key_hint') }}</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="key_name">{{ __('sites.sftp.keys.name') }}</label>
                        <input type="text" id="key_name" name="key_name" value="{{ old('key_name') }}" maxlength="100"
                            @class(['form-control', 'is-invalid' => $errors->has('key_name')])>
                        <div class="form-hint">{{ __('sites.sftp.keys.name_hint') }}</div>
                    </div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="plus" class="me-1" />{{ __('sites.sftp.keys.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

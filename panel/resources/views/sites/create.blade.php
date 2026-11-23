<x-layouts.app :title="__('sites.create')">
    <x-slot:sidebar>
        @include('sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.create')" :pretitle="__('sites.title')" />

    @if ($servers->isEmpty())
        <div class="row row-cards">
            <div class="col-lg-6">
                <x-card>
                    <p class="text-secondary mb-0">{{ __('sites.no_server') }}</p>
                </x-card>
            </div>
        </div>
    @else
        {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
        <div class="row row-cards align-items-start">
            <div class="col-lg-8">
                <form method="POST" action="{{ route('sites.store') }}" novalidate data-ow-busy="{{ __('sites.form.working') }}">
                    @csrf
                    <x-card :title="__('sites.form.title')">
                        <div class="mb-3">
                            <label class="form-label required" for="domain">{{ __('sites.form.domain') }}</label>
                            <input type="text" id="domain" name="domain" value="{{ old('domain') }}" required autofocus
                                maxlength="253" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="url"
                                placeholder="example.de" aria-describedby="domain-hint"
                                @class(['form-control', 'is-invalid' => $errors->has('domain')])>
                            @error('domain')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-hint" id="domain-hint">{{ __('sites.form.domain_hint') }}</div>
                        </div>

                        @if ($servers->count() > 1)
                            <div class="mb-3">
                                <label class="form-label required" for="server">{{ __('sites.form.server') }}</label>
                                <select id="server" name="server" required @class(['form-select', 'is-invalid' => $errors->has('server')])>
                                    <option value="">{{ __('sites.form.server_choose') }}</option>
                                    @foreach ($servers as $server)
                                        <option value="{{ $server->id }}" @selected(old('server') === $server->id)>{{ $server->display_name }}</option>
                                    @endforeach
                                </select>
                                @error('server')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="type">{{ __('sites.form.type') }}</label>
                            <input type="text" id="type" value="{{ __('sites.form.type_php') }}" class="form-control" readonly aria-describedby="type-hint">
                            <div class="form-hint" id="type-hint">{{ __('sites.form.type_hint') }}</div>
                        </div>

                        {{-- PHP version (E-172); the server checks that it has it. --}}
                        <div class="mb-3">
                            <label class="form-label" for="php">{{ __('sites.php.label') }}</label>
                            <select id="php" name="php" aria-describedby="php-create-hint" @class(['form-select', 'is-invalid' => $errors->has('php')])>
                                @foreach (config('owpanel.php_versions') as $version)
                                    <option value="{{ $version }}" @selected(old('php', config('owpanel.php_default')) === $version)>
                                        PHP {{ $version }}@if ($version === config('owpanel.php_default')) ({{ __('sites.php.default') }})@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('php')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-hint" id="php-create-hint">{{ __('sites.php.create_hint') }}</div>
                        </div>

                        {{-- www.<domain> too (E-110), only for main domains like example.de, not for subdomains like shop.example.de (E-120). --}}
                        @php($oldDomain = \App\Sites\Domain::normalize((string) old('domain', '')))
                        @php($oldMain = $oldDomain === null || \App\Models\Site::wwwOffered($oldDomain))
                        <div x-data="owWww" @input.window="domainInput($event)"
                             data-ow-touched="{{ old('www') !== null ? '1' : '0' }}" data-ow-www="{{ $oldMain && old('www', '1') === '1' ? '1' : '0' }}"
                             data-ow-main="{{ $oldMain ? '1' : '0' }}" data-ow-suffixes="{{ json_encode(\App\Sites\Domain::MULTI_PART_SUFFIXES) }}">
                            <label class="form-check mb-1">
                                <input type="hidden" name="www" value="0">
                                <input type="checkbox" class="form-check-input" name="www" value="1" x-model="www" @change="touch()" :disabled="sub"
                                    @checked($oldMain && old('www', '1') === '1') @disabled(! $oldMain) aria-describedby="www-hint" @class(['is-invalid' => $errors->has('www')])>
                                <span class="form-check-label">{{ __('sites.form.www') }}</span>
                            </label>
                            @error('www')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <div class="form-hint mb-3" id="www-hint">
                                <span x-show="main" @if (! $oldMain) style="display: none" @endif>{{ __('sites.form.www_hint') }}</span>
                                <span x-show="sub" @if ($oldMain) style="display: none" @endif>{{ __('sites.www.subdomain_off') }}</span>
                            </div>
                        </div>

                        @if ($dnsAvailable)
                            {{-- Create missing A and AAAA records in the Hetzner zone before the certificate (E-116). --}}
                            <label class="form-check mb-1">
                                <input type="hidden" name="dns" value="0">
                                <input type="checkbox" class="form-check-input" name="dns" value="1" @checked(old('dns', '1') === '1') aria-describedby="dns-hint">
                                <span class="form-check-label">{{ __('sites.form.dns') }}</span>
                            </label>
                            <div class="form-hint mb-3" id="dns-hint">{{ __('sites.form.dns_hint') }}</div>
                        @endif

                        <label class="form-check mb-1">
                            <input type="hidden" name="tls" value="0">
                            <input type="checkbox" class="form-check-input" name="tls" value="1" @checked(old('tls', '1') === '1') aria-describedby="tls-hint">
                            <span class="form-check-label">{{ __('sites.form.tls') }}</span>
                        </label>
                        <div class="form-hint" id="tls-hint">{{ __('sites.form.tls_hint') }}</div>

                        {{-- Owner (E-084, E-139): unassigned, administration or a customer. --}}
                        <div class="mt-3">
                            @include('sites._owner-select', ['selected' => (string) old('customer', $preselected ?? '')])
                        </div>

                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="plus" class="me-1" />{{ __('sites.form.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>

            <div class="col-lg-4">
                <x-card :title="__('sites.before.title')" tone="info">
                    <p class="mb-2">{{ __('sites.before.dns') }}</p>
                    @php($addressed = $servers->filter(fn ($server) => $server->public_ipv4 || $server->public_ipv6))
                    @if ($addressed->isEmpty())
                        <p class="text-secondary">{{ __('sites.before.dns_unknown') }}</p>
                    @else
                        <ul class="mb-3">
                            @foreach ($addressed as $server)
                                @foreach (['A' => $server->public_ipv4, 'AAAA' => $server->public_ipv6] as $record => $address)
                                    @if ($address)
                                        <li>
                                            @if ($servers->count() > 1)<span class="text-secondary">{{ $server->display_name }}:</span>@endif
                                            <code class="text-break">{{ $record }} {{ $address }}</code>
                                        </li>
                                    @endif
                                @endforeach
                            @endforeach
                        </ul>
                    @endif
                    <p>{{ __('sites.before.firewall') }}</p>
                    <p>{{ __('sites.before.user') }}</p>
                    <p class="mb-0">{{ __('sites.before.root') }}</p>
                </x-card>
            </div>
        </div>
    @endif
</x-layouts.app>

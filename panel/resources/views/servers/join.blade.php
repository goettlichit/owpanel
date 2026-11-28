<x-layouts.app :title="__('servers.join.title')">
    <x-slot:sidebar>
        <x-side-menu :title="__('ui.nav.servers')">
            <x-nav-link route="servers.index" icon="server">{{ __('servers.all') }}</x-nav-link>
            <x-nav-link route="servers.join" icon="circle-plus">{{ __('servers.join.title') }}</x-nav-link>
        </x-side-menu>
    </x-slot:sidebar>

    <x-page-header :title="__('servers.join.title')" :pretitle="__('ui.nav.servers')" />

    {{-- One-time code and command for a new website server (E-166, E-196). --}}
    <div class="row row-cards">
        @if ($code)
            <div class="col-12">
                <x-card :title="__('servers.join.command_title', ['name' => $name])">
                    <p>{{ __('servers.join.command_text') }}</p>
                    <ol class="mb-3">
                        <li class="mb-2">{{ __('servers.join.step_download') }}
                            <pre class="mt-1 mb-0"><code>curl -fsSL -o get-owpanel https://raw.githubusercontent.com/goettlichit/owpanel/main/get-owpanel
sha256sum get-owpanel</code></pre>
                            @if (\App\Support\PanelRelease::isTestBuild())
                                <div class="alert alert-info mt-2 mb-0">{{ __('servers.join.test_build') }}</div>
                            @endif
                        </li>
                        <li class="mb-2">{{ __('servers.join.step_run') }}
                            {{-- A test build has no signature, and only get-owpanel of its branch knows what it needs. --}}
                            @php($command = 'bash get-owpanel '.(\App\Support\PanelRelease::isTestBuild() ? '--dev ' : '').'--join '.$domain.' --via '.$panel?->private_ipv4)
                            <div class="d-flex align-items-start gap-2 mt-1">
                                <pre class="mb-0 flex-grow-1"><code>{{ $command }}</code></pre>
                                <x-copy-button :value="$command" />
                            </div>
                        </li>
                        <li>{{ __('servers.join.step_code') }}
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <code class="fs-3 text-break">{{ $code }}</code>
                                <x-copy-button :value="$code" />
                            </div>
                        </li>
                    </ol>
                    <div class="alert alert-warning mb-0">
                        {{ __('servers.join.once', ['minutes' => \App\Models\ServerJoinToken::MINUTES]) }}
                        @if ($expires)
                            {{ __('servers.join.until') }} <x-time :value="\Illuminate\Support\Carbon::parse($expires)" />.
                        @endif
                    </div>
                </x-card>
            </div>
        @endif

        <div class="col-12">
            <form method="POST" action="{{ route('servers.join.store') }}" novalidate>
                @csrf
                <x-card :title="__('servers.join.form_title')">
                    <p class="text-secondary">{{ __('servers.join.text') }}</p>
                    @unless ($ready)
                        <div class="alert alert-danger">
                            {{ $panel?->private_ipv4 === null ? __('servers.join.no_private') : __('servers.join.no_archive') }}
                        </div>
                    @endunless
                    <label class="form-label" for="name">{{ __('servers.join.name') }}</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="63" placeholder="web2" autocomplete="off" spellcheck="false"
                        @class(['form-control', 'font-monospace', 'is-invalid' => $errors->has('name')]) @disabled(! $ready)>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-hint">{{ __('servers.join.name_hint') }}</div>
                    {{-- Backups like the panel server's, with an own subaccount (E-199). --}}
                    @if ($panel?->backupConfigured())
                        <label class="form-check mt-3 mb-0">
                            <input type="hidden" name="backup" value="0">
                            <input type="checkbox" class="form-check-input" name="backup" value="1" @checked(old('backup', '1') === '1') @disabled(! $ready)>
                            <span class="form-check-label">{{ __('servers.join.backup', ['box' => $panel->backup_box_name, 'path' => $panel->backup_path]) }}</span>
                            <span class="form-check-description">{{ __('servers.join.backup_hint') }}</span>
                        </label>
                    @else
                        <div class="form-hint mt-3">{{ __('servers.join.no_backup') }}</div>
                    @endif
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary" @disabled(! $ready)><x-icon name="key" class="me-1" />{{ __('servers.join.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

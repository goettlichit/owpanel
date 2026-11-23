<x-layouts.app :title="__('settings.services.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('settings.services.title')" :pretitle="__('ui.nav.system')" />

    <div class="row row-cards">
        <div class="col-12">
            <form method="POST" action="{{ route('settings.hetzner.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.hetzner.title')" :accent="$hetznerConfigured ? 'green' : 'blue'">
                    <x-slot:actions>
                        @if ($hetznerConfigured)
                            <span class="badge bg-green-lt"><x-icon name="plug-connected" class="me-1" />{{ __('settings.hetzner.on') }}</span>
                        @else
                            <span class="badge bg-secondary-lt">{{ __('settings.hetzner.off') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.hetzner.text') }}</p>
                    <p><x-icon name="lock-access" class="me-1 text-orange" /><strong>{{ __('settings.services.permissions') }}:</strong> {{ __('settings.services.permissions_hetzner') }}</p>
                    <label class="form-label" for="hetzner_token">{{ __('settings.hetzner.token') }}</label>
                    <input type="password" id="hetzner_token" name="hetzner_token" autocomplete="off" spellcheck="false"
                        placeholder="{{ $hetznerConfigured ? __('settings.hetzner.token_keep') : '' }}"
                        @class(['form-control font-monospace', 'is-invalid' => $errors->has('hetzner_token')])>
                    @error('hetzner_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-hint">{{ __('settings.hetzner.hint') }}</div>
                    @if ($hetznerConfigured)
                        <label class="form-check mt-2 mb-0">
                            <input type="checkbox" class="form-check-input" name="hetzner_token_remove" value="1">
                            <span class="form-check-label">{{ __('settings.hetzner.remove') }}</span>
                        </label>
                    @endif
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        <div class="col-12">
            <form method="POST" action="{{ route('settings.hetzner_dns.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.services.dns_title')" :accent="$dnsTokenSet ? 'green' : 'blue'">
                    <x-slot:actions>
                        @if ($dnsTokenSet)
                            <span class="badge bg-green-lt"><x-icon name="plug-connected" class="me-1" />{{ __('settings.hetzner.on') }}</span>
                        @elseif ($hetznerConfigured)
                            <span class="badge bg-secondary-lt">{{ __('settings.services.dns_shared') }}</span>
                        @else
                            <span class="badge bg-secondary-lt">{{ __('settings.hetzner.off') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.services.dns_text') }}</p>
                    <p><x-icon name="lock-access" class="me-1 text-orange" /><strong>{{ __('settings.services.permissions') }}:</strong> {{ __('settings.services.permissions_dns') }}</p>
                    <label class="form-label" for="hetzner_dns_token">{{ __('settings.hetzner.token') }}</label>
                    <input type="password" id="hetzner_dns_token" name="hetzner_dns_token" autocomplete="off" spellcheck="false"
                        placeholder="{{ $dnsTokenSet ? __('settings.hetzner.token_keep') : '' }}"
                        @class(['form-control font-monospace', 'is-invalid' => $errors->has('hetzner_dns_token')])>
                    @error('hetzner_dns_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-hint">{{ __('settings.services.dns_hint') }}</div>
                    @if ($dnsTokenSet)
                        <label class="form-check mt-2 mb-0">
                            <input type="checkbox" class="form-check-input" name="hetzner_dns_token_remove" value="1">
                            <span class="form-check-label">{{ __('settings.hetzner.remove') }}</span>
                        </label>
                    @endif
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        {{-- Optional API key of Pocket ID for creating and linking customers (E-138). --}}
        <div class="col-12">
            <form method="POST" action="{{ route('settings.pocket_id_api.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.pocket_id_api.title')" :accent="$pocketIdKeySet ? 'green' : 'blue'">
                    <x-slot:actions>
                        @if ($pocketIdKeySet)
                            <span class="badge bg-green-lt"><x-icon name="plug-connected" class="me-1" />{{ __('settings.hetzner.on') }}</span>
                        @else
                            <span class="badge bg-secondary-lt">{{ __('settings.hetzner.off') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.pocket_id_api.text') }}</p>
                    <p><x-icon name="lock-access" class="me-1 text-orange" /><strong>{{ __('settings.services.permissions') }}:</strong> {{ __('settings.pocket_id_api.permissions', ['scopes' => implode(', ', App\Oidc\PocketIdApi::SCOPES)]) }}</p>
                    @if ($pocketIdIssuer === null)
                        <p class="text-secondary mb-0">{{ __('settings.pocket_id_api.no_issuer') }} <a href="{{ route('settings.oidc') }}">{{ __('settings.oidc_page.title') }}</a></p>
                    @else
                        <label class="form-label" for="pocket_id_api_key">{{ __('settings.pocket_id_api.key') }}</label>
                        <input type="password" id="pocket_id_api_key" name="pocket_id_api_key" autocomplete="off" spellcheck="false"
                            placeholder="{{ $pocketIdKeySet ? __('settings.hetzner.token_keep') : '' }}"
                            @class(['form-control font-monospace', 'is-invalid' => $errors->has('pocket_id_api_key')])>
                        @error('pocket_id_api_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-hint">{{ __('settings.pocket_id_api.hint', ['url' => rtrim($pocketIdIssuer, '/').'/settings/account']) }}</div>
                        @if ($pocketIdKeySet)
                            <label class="form-check mt-2 mb-0">
                                <input type="checkbox" class="form-check-input" name="pocket_id_api_key_remove" value="1">
                                <span class="form-check-label">{{ __('settings.hetzner.remove') }}</span>
                            </label>
                        @endif
                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                        </x-slot:footer>
                    @endif
                </x-card>
            </form>
        </div>

        {{-- Heartbeat to an outside monitoring (E-160). --}}
        <div class="col-12">
            <form method="POST" action="{{ route('settings.heartbeat.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.heartbeat.title')" :accent="$heartbeatSet ? ($heartbeatState && ! $heartbeatState['ok'] ? 'red' : 'green') : 'blue'">
                    <x-slot:actions>
                        @if ($heartbeatSet)
                            <span class="badge bg-green-lt"><x-icon name="heart-rate-monitor" class="me-1" />{{ __('settings.hetzner.on') }}</span>
                        @else
                            <span class="badge bg-secondary-lt">{{ __('settings.hetzner.off') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.heartbeat.text') }}</p>
                    @if ($heartbeatState)
                        <p @class(['mb-3', 'text-danger' => ! $heartbeatState['ok']])>
                            @if ($heartbeatState['ok'])
                                <x-icon name="circle-check" class="me-1 text-green" />{{ __('settings.heartbeat.last_ok') }}
                            @else
                                <x-icon name="alert-triangle" class="me-1" />{{ __('settings.heartbeat.last_failed', ['error' => $heartbeatState['error']]) }}
                            @endif
                            <x-time :value="\Illuminate\Support\Carbon::parse($heartbeatState['at'])" />
                        </p>
                    @endif
                    <label class="form-label" for="heartbeat_url">{{ __('settings.heartbeat.url') }}</label>
                    <input type="password" id="heartbeat_url" name="heartbeat_url" autocomplete="off" spellcheck="false" inputmode="url"
                        placeholder="{{ $heartbeatSet ? __('settings.hetzner.token_keep') : 'https://' }}"
                        @class(['form-control font-monospace', 'is-invalid' => $errors->has('heartbeat_url')])>
                    @error('heartbeat_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-hint">{{ __('settings.heartbeat.hint') }}</div>
                    @if ($heartbeatSet)
                        <label class="form-check mt-2 mb-0">
                            <input type="checkbox" class="form-check-input" name="heartbeat_url_remove" value="1">
                            <span class="form-check-label">{{ __('settings.hetzner.remove') }}</span>
                        </label>
                    @endif
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.heartbeat.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

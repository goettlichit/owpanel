<x-layouts.app :title="__('settings.oidc.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('settings.oidc.title')" :pretitle="__('ui.nav.system')" />

    <div class="row row-cards">
        <div class="col-12">
            <form method="POST" action="{{ route('settings.oidc.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.oidc.title')" :accent="$oidc['configured'] ? 'green' : 'blue'">
                    <x-slot:actions>
                        @if ($oidc['configured'])
                            <span class="badge bg-green-lt"><x-icon name="key" class="me-1" />{{ __('settings.oidc.on') }}</span>
                        @else
                            <span class="badge bg-secondary-lt"><x-icon name="key-off" class="me-1" />{{ __('settings.oidc.off') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.oidc.text') }}</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="oidc_name">{{ __('settings.oidc.name') }}</label>
                            <input type="text" id="oidc_name" name="oidc_name" value="{{ old('oidc_name', $oidc['name']) }}" maxlength="50" placeholder="{{ \App\Oidc\OidcSettings::DEFAULT_NAME }}" autocomplete="off"
                                @class(['form-control', 'is-invalid' => $errors->has('oidc_name')])>
                            @error('oidc_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-hint">{{ __('settings.oidc.name_hint') }}</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="oidc_issuer">{{ __('settings.oidc.issuer') }}</label>
                            <input type="url" id="oidc_issuer" name="oidc_issuer" value="{{ old('oidc_issuer', $oidc['issuer']) }}" maxlength="255" placeholder="https://login.example.de" autocomplete="off"
                                @class(['form-control', 'is-invalid' => $errors->has('oidc_issuer')])>
                            @error('oidc_issuer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="oidc_client_id">{{ __('settings.oidc.client_id') }}</label>
                            <input type="text" id="oidc_client_id" name="oidc_client_id" value="{{ old('oidc_client_id', $oidc['client_id']) }}" maxlength="255" autocomplete="off"
                                @class(['form-control', 'is-invalid' => $errors->has('oidc_client_id')])>
                            @error('oidc_client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="oidc_client_secret">{{ __('settings.oidc.client_secret') }}</label>
                            <input type="password" id="oidc_client_secret" name="oidc_client_secret" maxlength="512" autocomplete="new-password"
                                placeholder="{{ $oidc['secret_set'] ? __('settings.oidc.secret_keep') : '' }}"
                                @class(['form-control', 'is-invalid' => $errors->has('oidc_client_secret')])>
                            @error('oidc_client_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="form-label">{{ __('settings.oidc.redirect') }}</div>
                            <code class="text-break">{{ $oidc['redirect_url'] }}</code><x-copy-button :value="$oidc['redirect_url']" />
                            <div class="form-hint">{{ __('settings.oidc.redirect_hint') }}</div>
                        </div>
                    </div>
                    <div class="form-hint mt-3">{{ __('settings.oidc.hint') }}</div>
                    <x-slot:footer>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                            @if ($oidc['configured'])
                                <button type="submit" form="oidc-test" class="btn btn-outline-primary"><x-icon name="plug-connected" class="me-1" />{{ __('settings.oidc.test') }}</button>
                            @endif
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
            <form method="POST" action="{{ route('settings.oidc.test') }}" id="oidc-test" class="d-none">@csrf</form>
        </div>
    </div>
</x-layouts.app>

<x-layouts.app :title="__('account.security.title')">
    <x-slot:sidebar>
        <x-account-menu />
    </x-slot:sidebar>

    <x-page-header :title="__('account.security.title')" :pretitle="__('account.title')" />
    <p class="text-secondary mb-3">{{ __('account.security.description') }}</p>

    {{-- Two-factor and Pocket ID side by side; the password stays in the profile. --}}
    {{-- Equal height once two-factor is active; during the setup the QR code card is much taller. --}}
    @php
        $sameHeight = $user->hasConfirmedTwoFactor();
    @endphp
    <div @class(['row row-cards', 'align-items-start' => ! $sameHeight])>
        {{-- Two-factor authentication --}}
        <div class="col-lg-6">
            @if ($user->two_factor_secret === null)
                {{-- Not set up yet --}}
                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <x-card :title="__('account.two_factor.setup_title')" tone="info">
                        <p @class(['mb-0' => ! $user->isAdmin()])>{{ __('account.two_factor.setup_intro') }}</p>
                        @if ($user->isAdmin())
                            <p class="text-secondary mb-0">{{ __('account.two_factor.required_for_admins') }}</p>
                        @endif
                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary"><x-icon name="shield-plus" class="me-1" />{{ __('account.two_factor.start') }}</button>
                        </x-slot:footer>
                    </x-card>
                </form>
            @elseif (! $user->hasConfirmedTwoFactor())
                {{-- Secret created, waiting for the first code --}}
                <form method="POST" action="{{ route('two-factor.confirm') }}" novalidate>
                    @csrf
                    <x-card :title="__('account.two_factor.confirm_title')" tone="info">
                        <p>{{ __('account.two_factor.scan') }}</p>
                        <div class="qr-code bg-white p-2 d-inline-block rounded border mb-3" aria-hidden="true">
                            {!! $user->twoFactorQrCodeSvg() !!}
                        </div>
                        <p class="mb-1">{{ __('account.two_factor.manual_key') }}</p>
                        <p><code class="user-select-all">{{ \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) }}</code></p>
                        <label class="form-label required" for="code">{{ __('auth.two_factor.code') }}</label>
                        <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                            autocomplete="one-time-code" autofocus required
                            @class(['form-control', 'w-auto', 'is-invalid' => $errors->confirmTwoFactorAuthentication->has('code')])>
                        @if ($errors->confirmTwoFactorAuthentication->has('code'))
                            <div class="invalid-feedback">{{ $errors->confirmTwoFactorAuthentication->first('code') }}</div>
                        @endif
                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary"><x-icon name="check" class="me-1" />{{ __('account.two_factor.confirm') }}</button>
                        </x-slot:footer>
                    </x-card>
                </form>
            @else
                {{-- Active --}}
                <x-card :title="__('account.two_factor.active_title')" tone="success" class="h-100">
                    <x-slot:actions>
                        <x-badge tone="ok" class="text-uppercase">{{ __('ui.state.active') }}</x-badge>
                    </x-slot:actions>
                    <p @class(['mb-0' => ! in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true)])>
                        {{ __('account.two_factor.enabled_since', ['date' => $user->two_factor_confirmed_at->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L')]) }}
                    </p>

                    @if (in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true))
                        <div class="alert alert-warning mb-0" role="alert">
                            <h3 class="alert-title h4">{{ __('account.two_factor.recovery_title') }}</h3>
                            <p>{{ __('account.two_factor.recovery_intro') }}</p>
                            <ul class="list-unstyled font-monospace mb-0 user-select-all">
                                @foreach ($user->recoveryCodes() as $code)
                                    <li>{{ $code }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <x-slot:footer>
                        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning"><x-icon name="refresh" class="me-1" />{{ __('account.two_factor.regenerate') }}</button>
                        </form>
                        @unless ($user->isAdmin())
                            <form method="POST" action="{{ route('two-factor.disable') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger"><x-icon name="shield-off" class="me-1" />{{ __('account.two_factor.disable') }}</button>
                            </form>
                        @endunless
                    </x-slot:footer>
                </x-card>
            @endif
        </div>
        {{-- Login with Pocket ID (E-114): each account links only itself. --}}
        @php
            $oidcName = \App\Oidc\OidcSettings::name();
            $oidcIssuer = \App\Oidc\OidcSettings::configured() && $user->mayUseOidc() ? \App\Oidc\OidcSettings::issuer() : null;
            $oidcIdentity = $user->oidcIdentities()->first();
            // Administrators see the card even before it is set up, with the way to the settings.
            $oidcAdmin = $user->can('manage', \App\Models\Setting::class);
            $oidcLink = $oidcIssuer !== null && ($oidcIdentity === null || $oidcIdentity->issuer !== $oidcIssuer);
        @endphp
        @if ($oidcIssuer !== null || $oidcIdentity !== null || $oidcAdmin)
            <div class="col-lg-6">
                <x-card :title="__('auth.oidc.title', ['name' => $oidcName])" :accent="$oidcIdentity ? 'green' : 'blue'" :class="$sameHeight ? 'h-100' : ''">
                    @if ($oidcIdentity)
                        <x-slot:actions>
                            <x-badge tone="ok" class="text-uppercase">{{ __('ui.state.active') }}</x-badge>
                        </x-slot:actions>
                        <p class="mb-1">{{ __('auth.oidc.linked_as', ['account' => $oidcIdentity->email ?? $oidcIdentity->subject]) }}</p>
                        <p @class(['text-secondary', 'mb-0' => $oidcIssuer === null || $oidcIdentity->issuer === $oidcIssuer])>
                            {{ __('auth.oidc.linked_since', ['date' => $oidcIdentity->created_at->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L')]) }},
                            {{ $oidcIdentity->last_login_at
                                ? __('auth.oidc.last_login', ['date' => $oidcIdentity->last_login_at->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L LT')])
                                : __('auth.oidc.never') }}
                        </p>
                        @if ($oidcIssuer !== null && $oidcIdentity->issuer !== $oidcIssuer)
                            <div class="alert alert-warning mb-0" role="alert">{{ __('auth.oidc.other_issuer') }}</div>
                        @endif
                    @elseif ($oidcIssuer === null)
                        <p class="text-secondary mb-0">{{ __('auth.oidc.not_set_up', ['name' => $oidcName]) }}</p>
                    @else
                        <p>{{ __('auth.oidc.intro', ['name' => $oidcName]) }}</p>
                        <p class="text-secondary mb-0">{{ __('auth.oidc.not_linked') }}</p>
                    @endif
                    @if ($oidcLink || $oidcIdentity || $oidcAdmin)
                    <x-slot:footer>
                        @if ($oidcAdmin)
                            <a href="{{ route('settings.oidc') }}" class="btn btn-outline-primary"><x-icon name="settings" class="me-1" />{{ __('account.security.oidc_settings') }}</a>
                        @endif
                        @if ($oidcLink)
                            <form method="POST" action="{{ route('account.oidc.link') }}">
                                @csrf
                                <button type="submit" class="btn btn-primary"><x-icon name="fingerprint" class="me-1" />{{ __('auth.oidc.link', ['name' => $oidcName]) }}</button>
                            </form>
                        @endif
                        @if ($oidcIdentity)
                            <form method="POST" action="{{ route('account.oidc.unlink') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger"><x-icon name="link-off" class="me-1" />{{ __('auth.oidc.unlink') }}</button>
                            </form>
                        @endif
                    </x-slot:footer>
                    @endif
                </x-card>
            </div>
        @endif
    </div>
</x-layouts.app>

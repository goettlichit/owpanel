<x-layouts.guest :title="__('auth.login.title')">
    <h1 class="h2 text-center mb-4">{{ __('auth.login.title') }}</h1>

    <x-flash />

    <form method="POST" action="{{ route('login') }}" autocomplete="on" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">{{ __('auth.fields.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                @class(['form-control', 'is-invalid' => $errors->has('email')])
                @error('email') aria-describedby="email-error" aria-invalid="true" @enderror>
            @error('email')
                <div class="invalid-feedback" id="email-error">{{ $message }}</div>
            @enderror
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">
                {{ __('auth.fields.password') }}
                {{-- Only with outgoing mail set up (E-094). --}}
                @if (\App\Support\MailSettings::configured())
                    <span class="form-label-description"><a href="{{ route('password.request') }}">{{ __('auth.forgot.link') }}</a></span>
                @endif
            </label>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                @class(['form-control', 'is-invalid' => $errors->has('password')])>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="mb-3">
            <label class="form-check">
                <input type="checkbox" class="form-check-input" name="remember" value="1" @checked(old('remember'))>
                <span class="form-check-label">{{ __('auth.login.remember') }}</span>
            </label>
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <x-icon name="login-2" class="me-1" />{{ __('auth.login.submit') }}
        </button>
    </form>

    {{-- Login with Pocket ID (E-114) beside password and two-factor. --}}
    @if (\App\Oidc\OidcSettings::configured())
        <div class="hr-text">{{ __('auth.oidc.or') }}</div>
        <a href="{{ route('oidc.redirect') }}" class="btn btn-outline-primary w-100">
            <x-icon name="fingerprint" class="me-1" />{{ __('auth.oidc.login', ['name' => \App\Oidc\OidcSettings::name()]) }}
        </a>
    @endif
</x-layouts.guest>

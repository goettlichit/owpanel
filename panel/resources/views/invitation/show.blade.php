{{-- Invitation to the customer area (E-128). --}}
<x-layouts.guest :title="__('auth.invitation.title')">
    <h1 class="h2 text-center mb-2">{{ __('auth.invitation.title') }}</h1>
    @if ($account === null)
        <p class="text-secondary text-center mb-4">{{ __('auth.invitation.invalid') }}</p>
        <a href="{{ route('login') }}" class="btn btn-outline-primary w-100">{{ __('auth.invitation.to_login') }}</a>
    @else
        <p class="text-secondary text-center mb-4">{{ __('auth.invitation.intro') }}</p>

        <form method="POST" action="{{ route('invitation.store', ['user' => $user, 'token' => $token]) }}" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">{{ __('auth.fields.email') }}</label>
                <input type="email" id="email" value="{{ $account->email }}" readonly autocomplete="username" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">{{ __('auth.reset.password') }}</label>
                <input type="password" id="password" name="password" required autofocus autocomplete="new-password"
                    @class(['form-control', 'is-invalid' => $errors->has('password')])>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-hint">{{ __('auth.reset.hint') }}</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirmation">{{ __('auth.reset.confirmation') }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary w-100"><x-icon name="key" class="me-1" />{{ __('auth.invitation.submit') }}</button>
        </form>
    @endif
</x-layouts.guest>

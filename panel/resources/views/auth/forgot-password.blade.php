<x-layouts.guest :title="__('auth.forgot.title')">
    <h1 class="h2 text-center mb-2">{{ __('auth.forgot.title') }}</h1>
    <p class="text-secondary text-center mb-4">{{ __('auth.forgot.intro') }}</p>

    <x-flash />

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">{{ __('auth.fields.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                @class(['form-control', 'is-invalid' => $errors->has('email')])>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100"><x-icon name="mail-forward" class="me-1" />{{ __('auth.forgot.submit') }}</button>
    </form>
    <div class="text-center mt-3">
        <a href="{{ route('login') }}" class="link-secondary">{{ __('auth.forgot.back') }}</a>
    </div>
</x-layouts.guest>

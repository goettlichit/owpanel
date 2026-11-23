<x-layouts.guest :title="__('auth.reset.title')">
    <h1 class="h2 text-center mb-2">{{ __('auth.reset.title') }}</h1>
    <p class="text-secondary text-center mb-4">{{ __('auth.reset.intro') }}</p>

    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div class="mb-3">
            <label class="form-label" for="email">{{ __('auth.fields.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email', $request->query('email')) }}" required autocomplete="username"
                @class(['form-control', 'is-invalid' => $errors->has('email')])>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
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
        <button type="submit" class="btn btn-primary w-100"><x-icon name="key" class="me-1" />{{ __('auth.reset.submit') }}</button>
    </form>
</x-layouts.guest>

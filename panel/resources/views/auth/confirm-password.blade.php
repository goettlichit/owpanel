<x-layouts.guest :title="__('auth.confirm.title')">
    <h1 class="h2 text-center mb-2">{{ __('auth.confirm.title') }}</h1>
    <p class="text-secondary text-center mb-4">{{ __('auth.confirm.intro') }}</p>

    <form method="POST" action="{{ route('password.confirm') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="password">{{ __('auth.fields.password') }}</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="current-password"
                @class(['form-control', 'is-invalid' => $errors->has('password')])>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">{{ __('auth.confirm.submit') }}</button>
    </form>
</x-layouts.guest>

<x-layouts.guest :title="__('auth.two_factor.title')">
    <h1 class="h2 text-center mb-2">{{ __('auth.two_factor.title') }}</h1>
    <p class="text-secondary text-center mb-4">{{ __('auth.two_factor.intro') }}</p>

    <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="code">{{ __('auth.two_factor.code') }}</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                autocomplete="one-time-code" autofocus
                @class(['form-control', 'is-invalid' => $errors->has('code')])>
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">{{ __('auth.two_factor.submit') }}</button>
    </form>

    <details class="mt-4" @if ($errors->has('recovery_code')) open @endif>
        <summary class="text-secondary">{{ __('auth.two_factor.use_recovery_code') }}</summary>
        <form method="POST" action="{{ route('two-factor.login') }}" class="mt-3" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label" for="recovery_code">{{ __('auth.two_factor.recovery_code') }}</label>
                <input type="text" id="recovery_code" name="recovery_code" autocomplete="off"
                    @class(['form-control', 'is-invalid' => $errors->has('recovery_code')])>
                @error('recovery_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn btn-outline-secondary w-100">{{ __('auth.two_factor.submit_recovery') }}</button>
        </form>
    </details>
</x-layouts.guest>

<x-layouts.app :title="__('account.title')">
    <x-slot:sidebar>
        <x-account-menu />
    </x-slot:sidebar>

    <x-page-header :title="__('account.title')" :pretitle="$user->role->label()" />

    <div class="row row-cards">
        {{-- Personal details: main data, neutral header, blue save button --}}
        <div class="col-lg-6">
            <form method="POST" action="{{ route('user-profile-information.update') }}" class="h-100" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('account.profile.details_title')" class="h-100">
                    @php($bag = $errors->updateProfileInformation)
                    <div class="row g-3">
                        @foreach (['first_name' => 'given-name', 'last_name' => 'family-name'] as $field => $autocomplete)
                            <div class="col-sm-6">
                                <label class="form-label required" for="{{ $field }}">{{ __('auth.fields.'.$field) }}</label>
                                <input type="text" id="{{ $field }}" name="{{ $field }}" required autocomplete="{{ $autocomplete }}"
                                    value="{{ old($field, $user->$field) }}"
                                    @class(['form-control', 'is-invalid' => $bag->has($field)])>
                                @if ($bag->has($field))
                                    <div class="invalid-feedback">{{ $bag->first($field) }}</div>
                                @endif
                            </div>
                        @endforeach
                        <div class="col-12">
                            <label class="form-label" for="email">{{ __('auth.fields.email') }}</label>
                            <input type="email" id="email" value="{{ $user->email }}" class="form-control" readonly aria-describedby="email-hint">
                            <div class="form-hint" id="email-hint">{{ __('account.profile.email_hint') }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-label">{{ __('account.profile.role') }}</div>
                            <x-badge :tone="$user->isAdmin() ? 'danger' : 'info'" class="text-uppercase">{{ $user->role->label() }}</x-badge>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-label">{{ __('account.profile.last_login') }}</div>
                            <span class="text-secondary">{{ $user->last_login_at?->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L LT') ?? '–' }}</span>
                        </div>
                    </div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('account.profile.save') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        {{-- Password: security relevant, orange --}}
        @if ($user->password !== null)
            <div class="col-lg-6">
                <form method="POST" action="{{ route('user-password.update') }}" class="h-100" novalidate>
                    @csrf
                    @method('PUT')
                    <x-card :title="__('account.password.title')" tone="warning" class="h-100">
                        @foreach (['current_password' => 'current-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'] as $field => $autocomplete)
                            <div class="mb-3">
                                <label class="form-label" for="{{ $field }}">{{ __('account.password.'.$field) }}</label>
                                <input type="password" id="{{ $field }}" name="{{ $field }}" autocomplete="{{ $autocomplete }}" required
                                    @class(['form-control', 'is-invalid' => $errors->updatePassword->has($field)])>
                                @if ($errors->updatePassword->has($field))
                                    <div class="invalid-feedback">{{ $errors->updatePassword->first($field) }}</div>
                                @endif
                            </div>
                        @endforeach
                        <x-slot:footer>
                            <button type="submit" class="btn btn-warning"><x-icon name="key" class="me-1" />{{ __('account.password.submit') }}</button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>

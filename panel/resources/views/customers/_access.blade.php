{{--
    Access of the customer to the customer area (E-128).
    Expects $customer, $access (the customer's account or null) and $mail
    (outgoing mail set up).
--}}
@php
    $password = session('customer.access_password');
    $state = match (true) {
        $access === null => 'none',
        $access->isLocked() => 'locked',
        $access->hasOpenInvitation() => 'invited',
        $access->invitation_token !== null => 'expired',
        $access->password_change_required => 'start_password',
        default => 'active',
    };
    $accent = ['none' => 'blue', 'locked' => 'red', 'invited' => 'blue', 'expired' => 'orange', 'start_password' => 'blue', 'active' => 'green'][$state];
@endphp
<x-card :title="__('customers.access.title')" :accent="$accent">
    @if ($state !== 'none')
        <x-slot:actions>
            <x-badge :tone="['locked' => 'danger', 'expired' => 'warning', 'active' => 'ok'][$state] ?? 'info'">{{ __('customers.access.states.'.$state) }}</x-badge>
        </x-slot:actions>
    @endif

    @if ($password)
        <div class="alert alert-warning" role="status">
            <div class="fw-medium mb-1">{{ __('customers.access.start_password') }}</div>
            <code class="user-select-all fs-3">{{ $password }}</code>
            <div class="small text-secondary mt-1">{{ __('customers.access.start_password_hint') }}</div>
        </div>
    @endif

    @if (session('pocket_id.provisioned'))
        <div @class(['alert', 'alert-warning' => session('pocket_id.provision_error'), 'alert-success' => ! session('pocket_id.provision_error')]) role="status">{{ session('pocket_id.provisioned') }}</div>
    @endif

    @if ($access === null)
        @can('createAccess', $customer)
            <form method="POST" action="{{ route('customers.access.store', $customer) }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label required" for="access_email">{{ __('customers.access.email') }}</label>
                    <input type="email" id="access_email" name="access_email" value="{{ old('access_email', $customer->email) }}" required autocomplete="off"
                        @class(['form-control', 'is-invalid' => $errors->has('access_email')])>
                    @error('access_email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-hint">{{ __('customers.access.email_hint') }}</div>
                </div>
                <div class="mb-3">
                    <span class="form-label">{{ __('customers.access.method') }}</span>
                    <label class="form-check">
                        <input type="radio" name="method" value="invite" class="form-check-input" @checked($mail && old('method', 'invite') === 'invite') @disabled(! $mail)>
                        <span class="form-check-label">{{ __('customers.access.methods.invite') }}</span>
                        <span class="form-check-description">{{ $mail ? __('customers.access.methods.invite_hint', ['hours' => App\Customers\CustomerAccess::INVITATION_HOURS]) : __('customers.access.methods.invite_no_mail') }}</span>
                    </label>
                    <label class="form-check mb-0">
                        <input type="radio" name="method" value="password" class="form-check-input" @checked(! $mail || old('method') === 'password')>
                        <span class="form-check-label">{{ __('customers.access.methods.password') }}</span>
                        <span class="form-check-description">{{ __('customers.access.methods.password_hint') }}</span>
                    </label>
                </div>
                @if (App\Http\Controllers\CustomerAccessController::pocketIdAvailable())
                    <div class="mb-3">
                        <label class="form-check mb-0">
                            <input type="checkbox" name="pocket_id" value="1" class="form-check-input" @checked(old('pocket_id'))>
                            <span class="form-check-label">{{ __('customers.access.pocket_id', ['name' => App\Oidc\OidcSettings::name()]) }}</span>
                            <span class="form-check-description">{{ __('customers.access.pocket_id_hint', ['name' => App\Oidc\OidcSettings::name(), 'hours' => App\Customers\CustomerPocketId::LINK_HOURS]) }}</span>
                        </label>
                    </div>
                @endif
                <button type="submit" class="btn btn-primary"><x-icon name="user-plus" class="me-1" />{{ __('customers.access.create') }}</button>
            </form>
        @endcan
    @else
        <dl class="row mb-3">
            <dt class="col-5 fw-normal text-secondary">{{ __('customers.access.email') }}</dt>
            <dd class="col-7 text-break">{{ $access->email }}</dd>
            @if ($state === 'invited')
                <dt class="col-5 fw-normal text-secondary">{{ __('customers.access.invitation_until') }}</dt>
                <dd class="col-7"><x-time :value="$access->invitation_expires_at" /></dd>
            @endif
            <dt class="col-5 fw-normal text-secondary">{{ __('customers.access.last_login') }}</dt>
            <dd class="col-7 mb-0">
                @if ($access->last_login_at)
                    <x-time :value="$access->last_login_at" />
                @else
                    <span class="text-secondary">{{ __('customers.access.never') }}</span>
                @endif
            </dd>
        </dl>
        <p class="text-secondary small">{{ __('customers.access.state_text.'.$state) }}</p>

        <div class="d-flex flex-wrap gap-2">
            @if ($mail && in_array($state, ['invited', 'expired', 'start_password'], true))
                <form method="POST" action="{{ route('customers.access.invite', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm"><x-icon name="mail-forward" class="me-1" />{{ __('customers.access.resend') }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('customers.access.password', $customer) }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm"><x-icon name="key" class="me-1" />{{ __('customers.access.reset_password') }}</button>
            </form>
            @if ($state === 'locked')
                <form method="POST" action="{{ route('customers.access.unlock', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm"><x-icon name="lock-open" class="me-1" />{{ __('customers.access.unlock') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('customers.access.lock', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning btn-sm"><x-icon name="lock" class="me-1" />{{ __('customers.access.lock') }}</button>
                </form>
            @endif
        </div>

        <details class="mt-3">
            <summary class="text-danger">{{ __('customers.access.delete.title') }}</summary>
            <form method="POST" action="{{ route('customers.access.destroy', $customer) }}" class="mt-2" novalidate>
                @csrf
                @method('DELETE')
                <p class="text-secondary small">{{ __('customers.access.delete.text') }}</p>
                <label class="form-label required" for="confirm_email">{{ __('customers.access.delete.confirm', ['email' => $access->email]) }}</label>
                <input type="text" id="confirm_email" name="confirm_email" required autocomplete="off" placeholder="{{ $access->email }}" class="form-control mb-2">
                <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" class="me-1" />{{ __('customers.access.delete.submit') }}</button>
            </form>
        </details>
    @endif
</x-card>

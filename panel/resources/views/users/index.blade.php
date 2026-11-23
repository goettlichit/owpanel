<x-layouts.app :title="__('users.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('users.title')" :pretitle="__('ui.nav.system')">
        <x-slot:actions>
            @can('create', App\Models\User::class)
                <a href="{{ route('users.create') }}" class="btn btn-primary">
                    <x-icon name="user-plus" class="me-1" />{{ __('users.create.title') }}
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($created)
        {{-- The start password, shown only this once. --}}
        <x-card :title="__('users.created.title', ['name' => $created['name']])" tone="warning" class="mb-3">
            <p>{{ __('users.created.text') }}</p>
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ __('users.fields.email') }}</dt>
                <dd class="col-sm-9"><code>{{ $created['email'] }}</code><x-copy-button :value="$created['email']" /></dd>
                <dt class="col-sm-3">{{ __('users.created.password') }}</dt>
                <dd class="col-sm-9 mb-0"><code>{{ $created['password'] }}</code><x-copy-button :value="$created['password']" /></dd>
            </dl>
            <x-slot:footer>
                <span class="text-secondary small">{{ __('users.created.once') }}</span>
            </x-slot:footer>
        </x-card>
    @endif

    <x-card :title="__('users.list.title')" :flush="true">
        <x-slot:actions>
            <span class="text-secondary small">{{ __('users.list.hint') }}</span>
        </x-slot:actions>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('users.fields.name') }}</th>
                        <th scope="col">{{ __('users.fields.role') }}</th>
                        <th scope="col">{{ __('users.fields.two_factor') }}</th>
                        @if ($oidcName)
                            <th scope="col">{{ $oidcName }}</th>
                        @endif
                        <th scope="col">{{ __('users.fields.last_login') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('users.fields.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $user->name }}
                                    @if ($user->is($current))
                                        <span class="badge bg-azure-lt ms-1">{{ __('users.list.you') }}</span>
                                    @endif
                                </div>
                                <div class="text-secondary small">{{ $user->email }}</div>
                            </td>
                            <td>{{ __('users.roles.'.$user->role->value) }}</td>
                            <td>
                                @if ($user->hasConfirmedTwoFactor())
                                    <span class="badge bg-green-lt"><x-icon name="shield-check" class="me-1" />{{ __('users.list.two_factor_on') }}</span>
                                @elseif ($user->password_change_required)
                                    <span class="badge bg-secondary-lt"><x-icon name="clock" class="me-1" />{{ __('users.list.not_signed_in') }}</span>
                                @else
                                    <span @class(['badge', 'bg-red-lt' => $user->isAdmin(), 'bg-secondary-lt' => ! $user->isAdmin()])><x-icon name="shield-x" class="me-1" />{{ __('users.list.two_factor_off') }}</span>
                                @endif
                            </td>
                            @if ($oidcName)
                                {{-- Pocket ID (E-114): always for administrators, customers only when turned on. --}}
                                <td>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        @if ($user->oidcIdentities->isNotEmpty())
                                            <span class="badge bg-green-lt"><x-icon name="fingerprint" class="me-1" />{{ __('users.oidc.state_linked') }}</span>
                                        @elseif ($user->mayUseOidc())
                                            <span class="badge bg-azure-lt">{{ __($user->isAdmin() ? 'users.oidc.state_always' : 'users.oidc.state_allowed') }}</span>
                                        @else
                                            <span class="badge bg-secondary-lt">{{ __('users.oidc.state_off') }}</span>
                                        @endif
                                        @if ($user->isCustomer() && $user->customer_id)
                                            {{-- Customers: Pocket ID is managed on the customer's page "Access" (E-138). --}}
                                            <a href="{{ route('customers.access', $user->customer_id) }}" class="btn btn-sm btn-outline-primary">{{ __('users.oidc.customer_access') }}</a>
                                        @elsecan('manageOidc', $user)
                                            <form method="POST" action="{{ route('users.oidc', $user) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="allowed" value="{{ $user->oidc_allowed ? '0' : '1' }}">
                                                <button type="submit" @class(['btn btn-sm', 'btn-outline-danger' => $user->oidc_allowed, 'btn-outline-primary' => ! $user->oidc_allowed])>
                                                    {{ __($user->oidc_allowed ? 'users.oidc.revoke' : 'users.oidc.allow') }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endif
                            <td class="text-secondary"><x-time :value="$user->last_login_at" /></td>
                            <td class="text-end">
                                @can('delete', $user)
                                    <details class="ow-inline-confirm">
                                        <summary class="btn btn-sm btn-outline-danger">
                                            <x-icon name="trash" class="me-1" />{{ __('users.delete.button') }}
                                        </summary>
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" class="mt-2 text-start" novalidate>
                                            @csrf
                                            @method('DELETE')
                                            <label class="form-label small" for="confirm-{{ $user->id }}">{{ __('users.delete.confirm', ['email' => $user->email]) }}</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" id="confirm-{{ $user->id }}" name="confirm_email" class="form-control" autocomplete="off" required>
                                                <button type="submit" class="btn btn-danger">{{ __('users.delete.submit') }}</button>
                                            </div>
                                        </form>
                                    </details>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>

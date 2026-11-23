{{-- Signed-in user: initials, full name and e-mail; menu with account and sign-out. --}}
@php($user = auth()->user())
<div {{ $attributes->class(['dropdown', 'ms-auto']) }}>
    <button type="button" class="ow-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('ui.user_menu') }}">
        <span class="avatar avatar-sm rounded-circle bg-primary-lt">{{ $user->initials }}</span>
        <span class="d-none d-sm-flex flex-column">
            <span class="fw-medium">{{ $user->name }}</span>
            <span class="small text-secondary">{{ $user->email }}</span>
        </span>
        <x-icon name="chevron-down" />
    </button>
    <div class="dropdown-menu dropdown-menu-end">
        <a class="dropdown-item" href="{{ route('account.show') }}"><x-icon name="user-circle" class="me-2" />{{ __('ui.nav.account') }}</a>
        <div class="dropdown-divider"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="dropdown-item"><x-icon name="logout" class="me-2" />{{ __('ui.logout') }}</button>
        </form>
    </div>
</div>

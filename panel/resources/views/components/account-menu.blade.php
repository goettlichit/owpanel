<x-side-menu :title="auth()->user()->name" :pretitle="__('ui.nav.account')">
    <x-nav-link route="account.show" icon="id">{{ __('account.profile.title') }}</x-nav-link>
    <x-nav-link route="account.security" icon="shield-lock">{{ __('account.security.title') }}</x-nav-link>
    @can('viewAny', \App\Models\PushSubscription::class)
        <x-nav-link route="account.notifications" icon="bell">{{ __('notifications.title') }}</x-nav-link>
    @endcan
</x-side-menu>

{{--
    First header row (E-057): logo, "Open Web Panel", greeting, language and
    appearance. Shared by the main layout and the sign-in pages (E-066). The
    background spans the window, the content lines up with the centred page.
    :shadow="true" when no second row follows, so the shadow sits under it.
--}}
@props(['shadow' => false])
<header @class(['ow-topbar', 'ow-topbar-shadow' => $shadow, 'd-print-none'])>
    <div class="ow-bar">
        <a href="{{ route('dashboard') }}" class="ow-brand" aria-label="{{ __('ui.home') }}">
            <x-logo :size="52" />
            <span class="ow-brand-name">{{ __('ui.product_name') }}</span>
        </a>
        <div class="ow-topbar-end">
            @auth
                <p class="ow-greeting">{{ \App\Support\Greeting::for(auth()->user()) }}</p>
            @endauth
            <div class="d-flex gap-2">
                <x-language-switch />
                <x-theme-switch />
            </div>
        </div>
    </div>
</header>

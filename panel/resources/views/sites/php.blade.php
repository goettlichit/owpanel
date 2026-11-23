@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('sites.menu.php').' · '.$site->display_domain">
    {{-- PHP version and settings of a website (E-172, E-173), shared by the administration and the customer area. --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.menu.php')" :pretitle="$site->display_domain" />

    <div class="row row-cards">
        @if ($site->status !== \App\Enums\SiteStatus::Active)
            <div class="col-12">
                <x-card>
                    <p class="text-secondary mb-0">{{ __('sites.menu.not_active') }}</p>
                </x-card>
            </div>
        @else
            @include('sites._php', ['action' => route($area.'sites.php', $site)])
            @include('sites._php_settings', ['action' => route($area.'sites.php.settings', $site)])
        @endif
    </div>
</x-layouts.app>

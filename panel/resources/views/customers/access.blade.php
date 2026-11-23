<x-layouts.app :title="__('customers.menu.access').' · '.$customer->display_name">
    <x-slot:sidebar>
        @include('customers._customer-menu')
    </x-slot:sidebar>

    @include('customers._header', ['title' => __('customers.menu.access')])

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8 d-flex flex-column gap-3">
            @include('customers._access')
            @if ($access && $oidc)
                @include('customers._pocket-id')
            @endif
        </div>
        <div class="col-lg-4">
            <x-card :title="__('customers.access.about.title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('customers.access.about.points') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

<x-layouts.app :title="__('packages.create.title')">
    <x-slot:sidebar>
        @include('customers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('packages.create.title')" :pretitle="__('packages.title')" />

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('packages.store') }}" novalidate>
                @csrf
                <x-card :title="__('packages.create.form')">
                    @include('packages._form')
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="plus" class="me-1" />{{ __('packages.create.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
        <div class="col-lg-4">
            @include('packages._about')
        </div>
    </div>
</x-layouts.app>

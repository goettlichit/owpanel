<x-layouts.app :title="__('customers.menu.details').' · '.$customer->display_name">
    <x-slot:sidebar>
        @include('customers._customer-menu')
    </x-slot:sidebar>

    @include('customers._header', ['title' => __('customers.menu.details')])

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('customers.update', $customer) }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('customers.edit.title')">
                    @include('customers._form')
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('customers.edit.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
        <div class="col-lg-4">
            <x-card :title="__('customers.about.title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('customers.about.points') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

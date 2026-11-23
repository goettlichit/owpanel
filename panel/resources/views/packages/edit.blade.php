<x-layouts.app :title="$package->name">
    <x-slot:sidebar>
        @include('customers._menu')
    </x-slot:sidebar>

    <x-page-header :title="$package->name" :pretitle="__('packages.title')">
        <x-slot:actions>
            <a href="{{ route('packages.index') }}" class="btn btn-outline-primary">
                <x-icon name="arrow-left" class="me-1" />{{ __('packages.back') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('packages.update', $package) }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('packages.edit.form')">
                    @include('packages._form')
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('packages.edit.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
        <div class="col-lg-4">
            @include('packages._about')
        </div>

        <div class="col-12">
            <x-card :title="__('packages.customers.title')" :flush="true">
                @if ($customers->isEmpty())
                    <div class="card-body text-secondary">{{ __('packages.customers.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('customers.fields.number') }}</th>
                                    <th scope="col">{{ __('customers.fields.name') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($customers as $customer)
                                    <tr>
                                        <td><a href="{{ route('customers.show', $customer) }}" class="fw-medium">{{ $customer->display_number }}</a></td>
                                        <td>{{ $customer->display_name }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="col-lg-6">
            <form method="POST" action="{{ route('packages.destroy', $package) }}" novalidate>
                @csrf
                @method('DELETE')
                <x-card :title="__('packages.delete.title')" tone="danger">
                    @can('delete', $package)
                        <p class="text-secondary mb-0">{{ __('packages.delete.text') }}</p>
                        <x-slot:footer>
                            <button type="submit" class="btn btn-danger"><x-icon name="trash" class="me-1" />{{ __('packages.delete.submit') }}</button>
                        </x-slot:footer>
                    @else
                        <p class="text-secondary mb-0">{{ __('packages.delete.in_use') }}</p>
                    @endcan
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

<x-layouts.app :title="__('customers.title')">
    <x-slot:sidebar>
        @include('customers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('customers.title')">
        <x-slot:actions>
            @can('create', App\Models\Customer::class)
                <a href="{{ route('customers.create') }}" class="btn btn-primary">
                    <x-icon name="user-plus" class="me-1" />{{ __('customers.create.title') }}
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-card :flush="true">
        @if ($customers->isEmpty())
            <div class="card-body">
                <p class="fw-medium mb-1">{{ __('customers.empty') }}</p>
                <p class="text-secondary mb-0">{{ __('customers.empty_hint') }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('customers.fields.number') }}</th>
                            <th scope="col">{{ __('customers.fields.name') }}</th>
                            <th scope="col">{{ __('customers.fields.external_id') }}</th>
                            <th scope="col">{{ __('customers.fields.package') }}</th>
                            <th scope="col">{{ __('customers.fields.sites') }}</th>
                            <th scope="col">{{ __('customers.fields.created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr>
                                <td><a href="{{ route('customers.show', $customer) }}" class="fw-medium">{{ $customer->display_number }}</a></td>
                                <td>
                                    <div class="fw-medium">{{ $customer->display_name }}</div>
                                    <div class="small text-secondary">
                                        @if ($customer->company){{ $customer->person_name }} · @endif{{ $customer->email }}
                                    </div>
                                </td>
                                <td>{{ $customer->external_id ?? '–' }}</td>
                                <td>
                                    @if ($customer->package)
                                        {{ $customer->package->name }}
                                    @else
                                        <x-badge tone="warning">{{ __('customers.package.no_package') }}</x-badge>
                                    @endif
                                </td>
                                <td>{{ $customer->sites_count }}</td>
                                <td class="text-nowrap"><x-time :value="$customer->created_at" :stacked="true" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>

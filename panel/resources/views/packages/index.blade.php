<x-layouts.app :title="__('packages.title')">
    <x-slot:sidebar>
        @include('customers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('packages.title')" :pretitle="__('customers.title')">
        <x-slot:actions>
            @can('create', App\Models\Package::class)
                <a href="{{ route('packages.create') }}" class="btn btn-primary">
                    <x-icon name="plus" class="me-1" />{{ __('packages.create.title') }}
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-card :flush="true">
        @if ($packages->isEmpty())
            <div class="card-body">
                <p class="fw-medium mb-1">{{ __('packages.empty') }}</p>
                <p class="text-secondary mb-0">{{ __('packages.empty_hint') }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('packages.fields.name') }}</th>
                            <th scope="col">{{ __('packages.fields.max_sites') }}</th>
                            <th scope="col">{{ __('packages.fields.storage_mb') }}</th>
                            <th scope="col">{{ __('packages.fields.max_databases') }}</th>
                            <th scope="col">{{ __('packages.fields.max_cron_jobs') }}</th>
                            <th scope="col">{{ __('packages.fields.min_cron_interval_short') }}</th>
                            <th scope="col">{{ __('packages.fields.daily_self_service_short') }}</th>
                            <th scope="col">{{ __('packages.customers_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($packages as $package)
                            <tr>
                                <td><a href="{{ route('packages.edit', $package) }}" class="fw-medium">{{ $package->name }}</a></td>
                                @foreach (App\Models\Package::LIMITS as $limit)
                                    <td class="text-nowrap">@include('packages._limit', ['package' => $package, 'limit' => $limit])</td>
                                @endforeach
                                <td>{{ $package->customers_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>

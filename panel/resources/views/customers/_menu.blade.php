{{-- Side menu of the "Customers" area: customers, then packages (E-125). --}}
<x-side-menu :title="__('customers.title')">
    <x-nav-link route="customers.index" :active="['customers.index', 'customers.show']" icon="users-group">{{ __('customers.all') }}</x-nav-link>
    @can('create', App\Models\Customer::class)
        <x-nav-link route="customers.create" icon="user-plus">{{ __('customers.create.title') }}</x-nav-link>
    @endcan
    @can('viewAny', App\Models\Package::class)
        <x-nav-link route="packages.index" :active="['packages.index', 'packages.edit', 'packages.create']" icon="package">{{ __('packages.title') }}</x-nav-link>
    @endcan
</x-side-menu>

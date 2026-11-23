{{-- Left menu of one customer, like the menu of a website. Expects $customer. --}}
<x-side-menu :pretitle="__('ui.nav.customers').' · '.$customer->display_number" :title="$customer->display_name">
    <x-nav-link route="customers.show" :params="$customer" icon="layout-dashboard">{{ __('customers.menu.overview') }}</x-nav-link>
    @can('update', $customer)
        <x-nav-link route="customers.details" :params="$customer" icon="id">{{ __('customers.menu.details') }}</x-nav-link>
        <x-nav-link route="customers.package.show" :params="$customer" icon="package">{{ __('customers.menu.package') }}</x-nav-link>
        <x-nav-link route="customers.access" :params="$customer" icon="key">{{ __('customers.menu.access') }}</x-nav-link>
    @endcan
    <x-nav-link route="customers.delete" :params="$customer" icon="trash">{{ __('customers.menu.delete') }}</x-nav-link>
    <x-nav-link route="customers.index" icon="arrow-left" :active="[]">{{ __('customers.all') }}</x-nav-link>
</x-side-menu>

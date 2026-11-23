{{-- Who a website belongs to, for tables and details (E-139). Expects $site. --}}
@switch ($site->owner())
    @case (\App\Models\Site::OWNER_CUSTOMER)
        <a href="{{ route('customers.show', $site->customer) }}" class="text-reset">{{ $site->customer->display_name }}</a>
        @break
    @case (\App\Models\Site::OWNER_ADMIN)
        <span class="text-secondary">{{ __('sites.customer.admin') }}</span>
        @break
    @default
        <span class="text-warning">{{ __('sites.customer.unassigned') }}</span>
@endswitch

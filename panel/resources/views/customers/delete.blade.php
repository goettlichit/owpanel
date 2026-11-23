<x-layouts.app :title="__('customers.menu.delete').' · '.$customer->display_name">
    <x-slot:sidebar>
        @include('customers._customer-menu')
    </x-slot:sidebar>

    @include('customers._header', ['title' => __('customers.menu.delete')])

    <div class="row row-cards">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('customers.destroy', $customer) }}" novalidate>
                @csrf
                @method('DELETE')
                <x-card :title="__('customers.delete.title')" tone="danger">
                    @can('delete', $customer)
                        <p class="text-secondary">{{ __('customers.delete.text') }}</p>
                        <label class="form-label required" for="confirm_number">{{ __('customers.delete.confirm', ['number' => $customer->display_number]) }}</label>
                        <input type="text" id="confirm_number" name="confirm_number" required autocomplete="off" placeholder="{{ $customer->display_number }}" class="form-control">
                        <x-slot:footer>
                            <button type="submit" class="btn btn-danger"><x-icon name="trash" class="me-1" />{{ __('customers.delete.submit') }}</button>
                        </x-slot:footer>
                    @else
                        {{-- Name the actual reason: websites, the access, or both. --}}
                        <p class="text-secondary mb-0">{{ __($hasSites ? ($hasAccess ? 'customers.delete.has_both' : 'customers.delete.has_sites') : 'customers.delete.has_access') }}</p>
                    @endcan
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

{{--
    Owner of a website (E-084, E-139): unassigned, the administration or a
    customer. Expects $customers and $selected ('' | 'admin' | customer ID).
--}}
<label class="form-label" for="customer">{{ __('sites.customer.label') }}</label>
<select id="customer" name="customer" @class(['form-select', 'is-invalid' => $errors->has('customer')])>
    <option value="">{{ __('sites.customer.unassigned') }}</option>
    <option value="{{ \App\Models\Site::OWNER_ADMIN }}" @selected($selected === \App\Models\Site::OWNER_ADMIN)>{{ __('sites.customer.admin') }}</option>
    @if ($customers->isNotEmpty())
        <optgroup label="{{ __('sites.customer.customers') }}">
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected($selected === $customer->id)>{{ $customer->display_number }} · {{ $customer->display_name }}</option>
            @endforeach
        </optgroup>
    @endif
</select>
@error('customer')
    <div class="invalid-feedback">{{ $message }}</div>
@enderror
<div class="form-hint">{{ __('sites.customer.owner_hint') }}</div>

{{-- Fields of a customer (E-084, E-085). Expects $customer. --}}
@if ($customer->exists)
    <div class="mb-3">
        <span class="form-label">{{ __('customers.fields.number') }}</span>
        <div class="fw-medium">{{ $customer->display_number }}</div>
        <div class="form-hint">{{ __('customers.fields.number_hint') }}</div>
    </div>
@endif
{{-- Two fields per line, also when the card spans the full width of the customer page. --}}
<div class="row">
    @foreach (['first_name' => 'given-name', 'last_name' => 'family-name'] as $field => $autocomplete)
        <div class="col-md-6 mb-3">
            <label class="form-label required" for="{{ $field }}">{{ __('customers.fields.'.$field) }}</label>
            <input type="text" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $customer->{$field}) }}" autocomplete="{{ $autocomplete }}" required
                @class(['form-control', 'is-invalid' => $errors->has($field)])>
            @error($field)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    @endforeach
    @foreach (['company' => ['text', 'organization', false], 'email' => ['email', 'email', true], 'external_id' => ['text', 'off', false]] as $field => [$type, $autocomplete, $required])
        <div class="col-md-6 mb-3">
            <label @class(['form-label', 'required' => $required]) for="{{ $field }}">{{ __('customers.fields.'.$field) }}</label>
            <input type="{{ $type }}" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $customer->{$field}) }}" autocomplete="{{ $autocomplete }}"
                @if ($required) required @endif
                @class(['form-control', 'is-invalid' => $errors->has($field)])>
            @error($field)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            @if (in_array($field, ['company', 'external_id'], true))
                <div class="form-hint">{{ __('customers.fields.'.$field.'_hint') }}</div>
            @endif
        </div>
    @endforeach
    <div class="col-md-6">
        <label class="form-label required" for="locale">{{ __('customers.fields.locale') }}</label>
        <select id="locale" name="locale" @class(['form-select', 'is-invalid' => $errors->has('locale')])>
            @foreach (['de', 'en'] as $locale)
                <option value="{{ $locale }}" @selected(old('locale', $customer->locale) === $locale)>{{ __('customers.locales.'.$locale) }}</option>
            @endforeach
        </select>
    </div>
</div>

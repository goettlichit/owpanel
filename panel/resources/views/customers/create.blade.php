<x-layouts.app :title="__('customers.create.title')">
    <x-slot:sidebar>
        @include('customers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('customers.create.title')" :pretitle="__('customers.title')" />

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('customers.store') }}" novalidate>
                @csrf
                <x-card :title="__('customers.create.form')">
                    @include('customers._form')
                    @if ($packages->isNotEmpty())
                        <div class="mt-3">
                            <label class="form-label" for="package_id">{{ __('customers.fields.package') }}</label>
                            <select id="package_id" name="package_id" @class(['form-select', 'is-invalid' => $errors->has('package_id')])>
                                <option value="">{{ __('customers.package.none') }}</option>
                                @foreach ($packages as $package)
                                    <option value="{{ $package->id }}" @selected(old('package_id') === $package->id)>{{ $package->name }}</option>
                                @endforeach
                            </select>
                            @error('package_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-hint">{{ __('customers.fields.package_hint') }}</div>
                        </div>
                    @endif
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="user-plus" class="me-1" />{{ __('customers.create.submit') }}</button>
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

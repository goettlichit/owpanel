<x-layouts.app :title="__('users.create.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('users.create.title')" :pretitle="__('users.title')" />

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('users.store') }}" novalidate>
                @csrf
                <x-card :title="__('users.create.form')" tone="warning">
                    @foreach (['first_name' => ['text', 'given-name'], 'last_name' => ['text', 'family-name'], 'email' => ['email', 'off']] as $field => [$type, $autocomplete])
                        <div class="mb-3">
                            <label class="form-label required" for="{{ $field }}">{{ __('users.fields.'.$field) }}</label>
                            <input type="{{ $type }}" id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" required autocomplete="{{ $autocomplete }}"
                                @class(['form-control', 'is-invalid' => $errors->has($field)])>
                            @error($field)
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                    <div class="mb-0">
                        <span class="form-label">{{ __('users.fields.role') }}</span>
                        <div>{{ __('users.roles.admin') }}</div>
                        <div class="form-hint">{{ __('users.create.role_hint') }}</div>
                    </div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-warning"><x-icon name="user-plus" class="me-1" />{{ __('users.create.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
        <div class="col-lg-4">
            <x-card :title="__('users.create.about_title')" tone="info">
                <ol class="mb-0 ps-3">
                    @foreach (__('users.create.steps') as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </x-card>
        </div>
    </div>
</x-layouts.app>

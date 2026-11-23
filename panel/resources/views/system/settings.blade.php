<x-layouts.app :title="__('settings.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('settings.title')" :pretitle="__('ui.nav.system')" />

    <div class="row row-cards">
        <div class="col-12">
            <form method="POST" action="{{ route('settings.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.legal.title')">
                    <p class="text-secondary">{{ __('settings.legal.text') }}</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="imprint_url">{{ __('settings.legal.imprint') }}</label>
                            <input type="url" id="imprint_url" name="imprint_url" value="{{ old('imprint_url', $imprintUrl) }}" maxlength="500" placeholder="https://example.de/impressum"
                                @class(['form-control', 'is-invalid' => $errors->has('imprint_url')])>
                            @error('imprint_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="privacy_url">{{ __('settings.legal.privacy') }}</label>
                            <input type="url" id="privacy_url" name="privacy_url" value="{{ old('privacy_url', $privacyUrl) }}" maxlength="500" placeholder="https://example.de/datenschutz"
                                @class(['form-control', 'is-invalid' => $errors->has('privacy_url')])>
                            @error('privacy_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-hint mt-2">{{ __('settings.legal.hint') }}</div>
                    <h4 class="mt-4 mb-2">{{ __('settings.help.title') }}</h4>
                    <p class="text-secondary">{{ __('settings.help.text') }}</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="help_url">{{ __('settings.help.url') }}</label>
                            <input type="url" id="help_url" name="help_url" value="{{ old('help_url', $helpUrl) }}" maxlength="500" placeholder="https://example.de/hilfe"
                                @class(['form-control', 'is-invalid' => $errors->has('help_url')])>
                            @error('help_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

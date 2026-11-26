<x-layouts.app :title="__('settings.ingress.menu')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('settings.ingress.menu')" :pretitle="__('ui.nav.system')" />

    {{-- The gateway takes all visitor requests (E-161); more settings for it follow in milestone 5. --}}
    <div class="row row-cards">
        <div class="col-12">
            <form method="POST" action="{{ route('settings.gateway.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.ingress.title')">
                    <p class="text-secondary">{{ __('settings.ingress.text') }}</p>
                    <div class="row g-3">
                        @foreach (['ipv4' => [$ingressIpv4, $panelServer?->public_ipv4, '203.0.113.10'], 'ipv6' => [$ingressIpv6, $panelServer?->public_ipv6, '2001:db8::10']] as $family => [$value, $default, $example])
                            <div class="col-md-6">
                                <label class="form-label" for="ingress_{{ $family }}">{{ __('settings.ingress.'.$family) }}</label>
                                <input type="text" id="ingress_{{ $family }}" name="ingress_{{ $family }}" value="{{ old('ingress_'.$family, $value) }}" maxlength="45"
                                    placeholder="{{ $default ?? $example }}" autocomplete="off" spellcheck="false"
                                    @class(['form-control', 'font-monospace', 'is-invalid' => $errors->has('ingress_'.$family)])>
                                @error('ingress_'.$family)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-hint">
                                    @if ($default)
                                        {{ __('settings.ingress.default', ['address' => $default]) }}
                                    @else
                                        {{ __('settings.ingress.no_default') }}
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-hint mt-2">{{ __('settings.ingress.hint') }}</div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>
    </div>
</x-layouts.app>

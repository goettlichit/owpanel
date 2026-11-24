<x-layouts.app :title="__('sites.menu.webserver').' · '.$site->display_domain">
    {{-- Own nginx rules of a website (E-184), administrators only. --}}
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.menu.webserver')" :pretitle="$site->display_domain" />

    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            @if ($site->status !== \App\Enums\SiteStatus::Active)
                <x-card>
                    <p class="text-secondary mb-0">{{ __('sites.menu.not_active') }}</p>
                </x-card>
            @else
                <form data-ow-busy="{{ __('tasks.busy.webserver') }}" data-ow-task="site-nginx" method="POST" action="{{ route('sites.webserver.update', $site) }}" novalidate>
                    @csrf
                    @method('PUT')
                    <x-card :title="__('sites.webserver.title')">
                        {{-- Blue without rules, green once on the server, orange if the
                             last transfer failed; a running transfer updates itself. --}}
                        <x-slot:actions>
                            @switch($state)
                                @case('pending')
                                    <livewire:task-badge :task="$lastTask" :label="__('sites.webserver.sync')" :key="$lastTask->id" />
                                    @break
                                @case('failed')
                                    <a href="{{ route('tasks.show', $lastTask) }}" class="badge bg-orange-lt text-decoration-none"><x-icon name="alert-triangle" class="me-1" />{{ __('sites.webserver.state.failed') }}</a>
                                    @break
                                @case('applied')
                                    <span class="badge bg-green-lt"><x-icon name="check" class="me-1" />{{ __('sites.webserver.state.applied') }}</span>
                                    @break
                                @default
                                    <span class="badge bg-blue-lt"><x-icon name="info-circle" class="me-1" />{{ __('sites.webserver.state.none') }}</span>
                            @endswitch
                        </x-slot:actions>
                        <div class="mb-3">
                            <label class="form-label" for="headers">{{ __('sites.webserver.headers') }}</label>
                            <textarea id="headers" name="headers" rows="6" spellcheck="false" aria-describedby="headers-hint"
                                placeholder="Strict-Transport-Security: max-age=31536000; includeSubDomains"
                                @class(['form-control font-monospace', 'is-invalid' => $errors->has('headers')])>{{ old('headers', $headers) }}</textarea>
                            @error('headers')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-hint" id="headers-hint">{{ __('sites.webserver.headers_hint', ['max' => \App\Sites\WebserverRules::MAX_HEADERS]) }}</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="paths">{{ __('sites.webserver.paths') }}</label>
                            <textarea id="paths" name="paths" rows="6" spellcheck="false" aria-describedby="paths-hint"
                                placeholder="/vendor/&#10;/composer.json"
                                @class(['form-control font-monospace', 'is-invalid' => $errors->has('paths')])>{{ old('paths', $paths) }}</textarea>
                            @error('paths')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-hint" id="paths-hint">{{ __('sites.webserver.paths_hint', ['max' => \App\Sites\WebserverRules::MAX_PATHS]) }}</div>
                        </div>
                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary" @disabled($busy)>
                                <x-icon name="device-floppy" class="me-1" />{{ __('sites.webserver.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            @endif
        </div>

        <div class="col-lg-4">
            <x-card :title="__('sites.webserver.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('sites.webserver.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

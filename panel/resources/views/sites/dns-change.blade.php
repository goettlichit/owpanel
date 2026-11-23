<x-layouts.app :title="__('dns.change.title').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('dns.change.title')" :pretitle="$site->display_domain" />

    {{-- Confirmation left, info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('sites.dns.apply', $site) }}" data-ow-busy="{{ __('dns.change.working') }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <x-card :title="__('dns.change.card')" :flush="true" accent="orange">
                    <div class="card-body">
                        <p class="mb-0">{{ __('dns.change.intro') }}</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('dns.fields.name') }}</th>
                                    <th scope="col">{{ __('dns.fields.type') }}</th>
                                    <th scope="col">{{ __('dns.change.current') }}</th>
                                    <th scope="col">{{ __('dns.change.new') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($changes as $change)
                                    <tr>
                                        <td><code>{{ $change['host'] }}</code><input type="hidden" name="records[]" value="{{ $change['key'] }}"></td>
                                        <td>{{ $change['type'] }}</td>
                                        <td>
                                            @if ($change['current'] === [])
                                                <span class="text-secondary">{{ __('dns.none') }}</span>
                                            @else
                                                <span class="text-danger">{{ implode(', ', $change['current']) }}</span>
                                            @endif
                                        </td>
                                        <td><code>{{ $change['expected'] }}</code></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body">
                        <label class="form-label" for="ttl">{{ __('dns.change.ttl') }}</label>
                        <select id="ttl" name="ttl" class="form-select w-auto">
                            @foreach (\App\Dns\SiteDnsWriter::TTLS as $seconds)
                                <option value="{{ $seconds }}" @selected($seconds === $ttl)>{{ __('dns.change.ttls.'.$seconds) }}</option>
                            @endforeach
                        </select>
                        @if (collect($changes)->contains(fn ($change) => $change['current'] !== []))
                            <div class="form-hint mt-3 text-warning"><x-icon name="alert-triangle" class="me-1" />{{ __('dns.change.overwrite') }}</div>
                        @endif
                    </div>
                    <div class="card-body pt-0 d-none" data-ow-busy-note role="status">
                        <div class="alert alert-info mb-0">{{ __('dns.change.working_note') }}</div>
                    </div>
                    <x-slot:footer>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <a href="{{ route('sites.dns', $site) }}" class="btn btn-outline-secondary">{{ __('dns.change.cancel') }}</a>
                            <button type="submit" class="btn btn-primary"><x-icon name="check" class="me-1" />{{ trans_choice('dns.change.submit', count($changes), ['count' => count($changes)]) }}</button>
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('dns.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('dns.change.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

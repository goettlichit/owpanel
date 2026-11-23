<x-layouts.app :title="__('sites.delete.title').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.delete.title')" :pretitle="$site->display_domain" />

    {{-- Second step before deleting (E-116): what goes away, the DNS question, the domain to type. --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('sites.destroy', $site) }}" novalidate data-ow-busy="{{ __('sites.delete.working') }}">
                @csrf
                @method('DELETE')
                <x-card :title="__('sites.delete.really', ['domain' => $site->display_domain])" tone="danger">
                    <p>{{ __('sites.delete.text') }}</p>
                    <ul class="mb-3">
                        <li>{{ __('sites.delete.lists.files', ['path' => $site->root_path]) }}</li>
                        <li>{{ trans_choice('sites.delete.lists.databases', $site->databases_count, ['count' => $site->databases_count]) }}</li>
                        <li>{{ __('sites.delete.lists.user', ['user' => $site->system_user]) }}</li>
                    </ul>

                    @can('manageDns', $site)
                        <h4 class="mt-4">{{ __('sites.delete.dns_title') }}</h4>
                        @if ($dnsRecords === [])
                            <p class="text-secondary">{{ __('sites.delete.dns_none') }}</p>
                        @else
                            <div class="table-responsive mb-2">
                                <table class="table table-sm table-vcenter">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('dns.fields.name') }}</th>
                                            <th scope="col">{{ __('dns.fields.type') }}</th>
                                            <th scope="col">{{ __('dns.change.current') }}</th>
                                            <th scope="col">{{ __('dns.fields.state') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($dnsRecords as $entry)
                                            <tr>
                                                <td><code>{{ $entry['host'] }}</code></td>
                                                <td>{{ $entry['record']->type }}</td>
                                                <td>{{ $entry['current'] === null ? __('dns.no_answer') : ($entry['current'] === [] ? __('dns.none') : implode(', ', $entry['current'])) }}</td>
                                                <td>
                                                    @if ($entry['removable'])
                                                        <x-badge tone="warning">{{ __('sites.delete.dns_removable') }}</x-badge>
                                                    @else
                                                        <x-badge tone="neutral">{{ __('sites.delete.dns_kept') }}</x-badge>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (collect($dnsRecords)->contains('removable', true))
                                <label class="form-check mb-1">
                                    <input type="hidden" name="delete_dns" value="0">
                                    <input type="checkbox" class="form-check-input" name="delete_dns" value="1" @checked(old('delete_dns') === '1')>
                                    <span class="form-check-label">{{ __('sites.delete.dns_question') }}</span>
                                </label>
                                <div class="form-hint mb-3">{{ __('sites.delete.dns_hint') }}</div>
                            @endif
                        @endif
                    @endcan

                    <label class="form-label required mt-3" for="confirm_domain">{{ __('sites.delete.confirm') }}</label>
                    <input type="text" id="confirm_domain" name="confirm_domain" required autocomplete="off"
                        autocapitalize="none" spellcheck="false" placeholder="{{ $site->display_domain }}"
                        @class(['form-control', 'is-invalid' => $errors->has('confirm_domain')])>
                    @error('confirm_domain')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <x-slot:footer>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <a href="{{ route('sites.show', $site) }}" class="btn btn-outline-secondary">{{ __('sites.delete.cancel') }}</a>
                            <button type="submit" class="btn btn-danger"><x-icon name="trash" class="me-1" />{{ __('sites.delete.submit') }}</button>
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('dns.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('sites.delete.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

<x-layouts.app :title="__('customers.menu.package').' · '.$customer->display_name">
    <x-slot:sidebar>
        @include('customers._customer-menu')
    </x-slot:sidebar>

    @include('customers._header', ['title' => __('customers.menu.package')])

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"). --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            @include('customers._package')

            @if ($customer->sites->isNotEmpty())
                {{-- Storage of all websites (E-126, E-149). --}}
                <x-card :title="__('storage.title')" :flush="true" class="mt-3"
                    :accent="['ok' => 'blue', 'warning' => 'orange', 'full' => 'red'][$storage['state']]">
                    <div class="card-body">
                        <x-storage-bar :used="$storage['used']" :limit="$storage['limit']" :measured="$customer->sites->max('usage_measured_at')"
                            :files="$storage['used'] === null ? null : (int) $customer->sites->sum('usage_files_bytes')"
                            :databases="(int) $customer->sites->sum('usage_db_bytes')" />
                        @if ($storage['limit'] !== null && $customer->sites->contains(fn ($site) => $site->server?->quota_active === false))
                            <p class="small text-orange mt-2 mb-0">{{ __('storage.quota_unavailable', ['filesystem' => $customer->sites->first(fn ($site) => $site->server?->quota_active === false)->server->storage_filesystem ?: __('storage.quota_unknown')]) }}</p>
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sites.columns.domain') }}</th>
                                    <th scope="col" class="text-end">{{ __('storage.files') }}</th>
                                    <th scope="col" class="text-end">{{ __('storage.databases') }}</th>
                                    <th scope="col" class="text-end">{{ __('storage.column') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($customer->sites as $site)
                                    <tr>
                                        <td class="text-break"><a href="{{ route('sites.show', $site) }}">{{ $site->display_domain }}</a></td>
                                        @if ($site->usageBytes() === null)
                                            <td class="text-end text-secondary" colspan="3">{{ __('sites.details.not_yet') }}</td>
                                        @else
                                            <td class="text-end text-nowrap">{{ App\Support\Units::bytes((int) $site->usage_files_bytes) }}</td>
                                            <td class="text-end text-nowrap">{{ App\Support\Units::bytes((int) $site->usage_db_bytes) }}</td>
                                            <td class="text-end text-nowrap fw-medium">{{ App\Support\Units::bytes($site->usageBytes()) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endif
        </div>
        <div class="col-lg-4">
            @include('packages._about')
        </div>
    </div>
</x-layouts.app>

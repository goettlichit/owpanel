<x-layouts.app :title="__('sites.title')">
    <x-slot:sidebar>
        @include('sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.title')" :pretitle="__('ui.brand')">
        @can('create', \App\Models\Site::class)
            <x-slot:actions>
                <a href="{{ route('sites.create') }}" class="btn btn-primary">
                    <x-icon name="plus" class="me-1" />{{ __('sites.create') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <x-card :flush="true">
        @if ($sites->isEmpty())
            <div class="card-body">
                <p class="mb-1">{{ __('sites.empty') }}</p>
                <p class="text-secondary mb-0">{{ __('sites.empty_hint') }}</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sites.columns.domain') }}</th>
                            <th scope="col">{{ __('sites.customer.label') }}</th>
                            <th scope="col">{{ __('sites.columns.status') }}</th>
                            <th scope="col">{{ __('sites.columns.tls') }}</th>
                            <th scope="col">{{ __('sites.columns.php') }}</th>
                            <th scope="col" class="text-end">{{ __('storage.column') }}</th>
                            <th scope="col">{{ __('sites.columns.created') }}</th>
                            {{-- Always shown, also with one server, for later multi-server use. --}}
                            <th scope="col">{{ __('sites.columns.server') }}</th>
                        </tr>
                    </thead>
                    {{-- Administration, unassigned, customers (E-139), divided by a thicker line. --}}
                    @foreach ($groups as $group)
                    <tbody @class(['table-group-divider' => ! $loop->first])>
                        @foreach ($group as $site)
                            <tr>
                                <td>
                                    <a href="{{ route('sites.show', $site) }}" class="text-break">{{ $site->display_domain }}</a>
                                    <div class="small text-secondary"><code>{{ $site->system_user }}</code></div>
                                </td>
                                <td>
                                    @include('sites._owner')
                                </td>
                                <td><x-status-badge :status="$site->badgeStatus()" /></td>
                                <td class="text-nowrap">@include('sites._tls', ['site' => $site])</td>
                                <td>{{ $site->php_version ?? '–' }}</td>
                                <td class="text-nowrap text-end">{{ $site->usageBytes() === null ? '–' : App\Support\Units::bytes($site->usageBytes()) }}</td>
                                <td class="text-nowrap"><x-time :value="$site->created_at" :stacked="true" /></td>
                                <td>
                                    @can('view', $site->server)
                                        <a href="{{ route('servers.show', $site->server) }}" class="text-reset">{{ $site->server->display_name }}</a>
                                    @else
                                        {{ $site->server->display_name }}
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>

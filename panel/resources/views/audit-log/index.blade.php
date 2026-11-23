<x-layouts.app :title="__('audit.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('audit.title')" :pretitle="__('ui.nav.system')" />

    <x-card :flush="true">
        @if ($entries->isEmpty())
            <div class="card-body text-secondary">{{ __('audit.empty') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('audit.columns.time') }}</th>
                            <th scope="col">{{ __('audit.columns.user') }}</th>
                            <th scope="col">{{ __('audit.columns.action') }}</th>
                            <th scope="col">{{ __('audit.columns.ip') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="text-nowrap">
                                    <x-time :value="$entry->created_at" />
                                </td>
                                <td>
                                    @if ($entry->user)
                                        <div>{{ $entry->user->name }}</div>
                                        <div class="small text-secondary">{{ $entry->user->email }}</div>
                                    @elseif ($entry->user_id)
                                        <span class="text-secondary">{{ __('audit.deleted_user') }}</span>
                                    @else
                                        <span class="text-secondary">–</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('audit-log.show', $entry) }}" class="text-reset" title="{{ __('audit.show.open') }}">
                                        <x-badge :tone="$entry->tone()">{{ \Illuminate\Support\Facades\Lang::has('audit.actions.'.$entry->action) ? __('audit.actions.'.$entry->action) : $entry->action }}</x-badge>
                                    </a>
                                </td>
                                <td class="text-secondary">{{ $entry->ip_address ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())
                <div class="card-footer">{{ $entries->links() }}</div>
            @endif
        @endif
    </x-card>
</x-layouts.app>

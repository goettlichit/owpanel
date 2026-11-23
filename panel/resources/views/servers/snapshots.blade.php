<x-layouts.app :title="__('snapshots.title').' · '.$server->display_name">
    <x-slot:sidebar>
        @include('servers._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('snapshots.title')" :pretitle="$server->display_name">
        @if ($configured && $server->snapshot_enabled)
            <x-slot:actions>
                <form method="POST" action="{{ route('servers.snapshots.store', $server) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" @disabled($busy)>
                        <x-icon name="camera" class="me-1" />{{ __('snapshots.create') }}
                    </button>
                </form>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="row row-cards align-items-start">
        @unless ($configured)
            <div class="col-12">
                <x-card :title="__('snapshots.state.not_configured_title')" accent="orange">
                    <p class="mb-0">{{ __('snapshots.state.not_configured') }}
                        <a href="{{ route('settings.services') }}">{{ __('settings.services.to_services') }}</a></p>
                </x-card>
            </div>
        @endunless
        @if ($error)
            <div class="col-12">
                <x-card :title="__('snapshots.state.error_title')" accent="red">
                    <p class="mb-0">{{ __('snapshots.errors.'.$error) }}</p>
                </x-card>
            </div>
        @endif

        {{-- Top: schedule and retention, with the rules next to it. --}}
        <div class="col-lg-8">
            <form method="POST" action="{{ route('servers.snapshots.schedule', $server) }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('snapshots.schedule.title')" :accent="$accent">
                    <label class="form-check form-switch mb-3">
                        <input type="checkbox" class="form-check-input" name="snapshot_enabled" value="1" @checked(old('snapshot_enabled', $server->snapshot_enabled))>
                        <span class="form-check-label">{{ __('snapshots.schedule.enabled') }}</span>
                    </label>
                    <div class="mb-3">
                        <label class="form-label" for="snapshot_time">{{ __('snapshots.schedule.time') }}</label>
                        <input type="time" id="snapshot_time" name="snapshot_time" value="{{ old('snapshot_time', $server->snapshot_time) }}"
                            @class(['form-control', 'is-invalid' => $errors->has('snapshot_time')])>
                        @error('snapshot_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-hint">{{ __('snapshots.schedule.time_hint', ['zone' => config('owpanel.timezone')]) }}</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="snapshot_keep_daily">{{ __('snapshots.schedule.daily') }}</label>
                            <input type="number" id="snapshot_keep_daily" name="snapshot_keep_daily" min="1" max="31" value="{{ old('snapshot_keep_daily', $server->snapshot_keep_daily) }}"
                                @class(['form-control', 'is-invalid' => $errors->has('snapshot_keep_daily')])>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="snapshot_keep_weekly">{{ __('snapshots.schedule.weekly') }}</label>
                            <input type="number" id="snapshot_keep_weekly" name="snapshot_keep_weekly" min="0" max="12" value="{{ old('snapshot_keep_weekly', $server->snapshot_keep_weekly) }}"
                                @class(['form-control', 'is-invalid' => $errors->has('snapshot_keep_weekly')])>
                        </div>
                    </div>
                    <div class="form-hint mt-2">{{ __('snapshots.schedule.hint') }}</div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('snapshots.schedule.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        <div class="col-lg-4">
            {{-- Which snapshots the panel deletes and which it keeps. --}}
            <x-card :title="__('snapshots.rules.title')" tone="info">
                <ul class="mb-0 ps-3">
                    <li>{!! __('snapshots.rules.schedule', ['daily' => '<strong>'.$server->snapshot_keep_daily.'</strong>', 'weekly' => '<strong>'.$server->snapshot_keep_weekly.'</strong>']) !!}</li>
                    <li>{{ __('snapshots.rules.newest') }}</li>
                    <li>{{ __('snapshots.rules.manual') }}</li>
                    <li>{{ __('snapshots.rules.own') }}</li>
                </ul>
            </x-card>
        </div>

        {{-- Below, across the full width: the runs and the snapshots, five per page. --}}
        <div class="col-12">
            <x-card :title="__('snapshots.runs.title')" :flush="true">
                <livewire:snapshot-runs :server="$server" />
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('snapshots.list.title')" :flush="true">
                @if ($server->hetzner_server_id)
                    <x-slot:actions>
                        <span class="small text-secondary">{{ __('snapshots.list.linked', ['id' => $server->hetzner_server_id]) }}</span>
                    </x-slot:actions>
                @endif
                @if (! $configured || $server->hetzner_server_id === null)
                    <div class="card-body text-secondary">{{ __('snapshots.list.not_linked') }}</div>
                @elseif ($images->isEmpty())
                    <div class="card-body text-secondary">{{ __('snapshots.list.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('snapshots.list.created') }}</th>
                                    <th scope="col">{{ __('snapshots.list.description') }}</th>
                                    <th scope="col">{{ __('snapshots.list.size') }}</th>
                                    <th scope="col">{{ __('snapshots.list.origin') }}</th>
                                    <th scope="col"><span class="visually-hidden">{{ __('snapshots.list.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($images as $image)
                                    <tr>
                                        <td class="text-nowrap"><x-time :value="\Illuminate\Support\Carbon::parse($image['created'])" /></td>
                                        <td>
                                            {{ $image['description'] }}
                                            @if ($image['status'] !== 'available')
                                                <span class="badge bg-blue-lt ms-1">{{ __('snapshots.list.statuses.'.$image['status']) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-nowrap">{{ $image['size_gb'] !== null ? number_format($image['size_gb'], 1, ',', '.').' GB' : '–' }}</td>
                                        <td>
                                            @if ($image['panel'] && $image['manual'])
                                                <span class="badge bg-green-lt" title="{{ __('snapshots.list.manual_hint') }}">{{ __('snapshots.list.manual') }}</span>
                                            @elseif ($image['panel'])
                                                <span class="badge bg-blue-lt" title="{{ __('snapshots.list.panel_hint') }}">{{ __('snapshots.list.panel') }}</span>
                                            @else
                                                <span class="badge bg-secondary-lt" title="{{ __('snapshots.list.own_hint') }}">{{ __('snapshots.list.own') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($image['panel'] && $image['status'] === 'available')
                                                <form method="POST" action="{{ route('servers.snapshots.destroy', [$server, $image['id']]) }}" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('snapshots.list.delete') }}" title="{{ __('snapshots.list.delete') }}">
                                                        <x-icon name="trash" />
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <x-slot:footer>
                    @if ($images instanceof \Illuminate\Pagination\LengthAwarePaginator && $images->hasPages())
                        <div class="mb-2">{{ $images->links() }}</div>
                    @endif
                    <div class="small text-secondary">{{ __('snapshots.list.hint') }}</div>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</x-layouts.app>

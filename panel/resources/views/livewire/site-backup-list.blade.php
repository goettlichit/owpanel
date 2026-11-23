<div>
    @if (isset($answer['error']))
        <div class="card-body text-danger">{{ __('backups.list.error', ['error' => \Illuminate\Support\Facades\Lang::has('tasks.errors.'.$answer['error']) ? __('tasks.errors.'.$answer['error']) : $answer['error']]) }}</div>
    @elseif ($answer['snapshots'] === [])
        <div class="card-body text-secondary">{{ __('backups.list.empty') }}</div>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('backups.list.time') }}</th>
                        <th scope="col">{{ __('backups.list.kind') }}</th>
                        <th scope="col">{{ __('backups.list.size') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('backups.list.open') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($answer['snapshots'] as $snapshot)
                        <tr>
                            <td class="text-nowrap">
                                <a href="{{ route($area.'sites.backups.show', [$site, $snapshot['id']]) }}" class="text-reset">
                                    @if ($snapshot['time'])<x-time :value="\Illuminate\Support\Carbon::parse($snapshot['time'])" />@else{{ $snapshot['id'] }}@endif
                                </a>
                                <div class="small text-secondary font-monospace">{{ $snapshot['id'] }}</div>
                            </td>
                            <td>
                                @php($tone = ['manual' => 'green', 'restore' => 'orange'][$snapshot['kind']] ?? 'blue')
                                <span class="badge bg-{{ $tone }}-lt">{{ __('backups.kinds.'.$snapshot['kind']) }}</span>
                            </td>
                            <td class="text-nowrap">
                                {{ $snapshot['size'] !== null ? \App\Support\Units::bytes($snapshot['size']) : '–' }}
                                @if ($snapshot['added'] !== null)
                                    <span class="small text-secondary">{{ __('backups.list.added', ['size' => \App\Support\Units::bytes($snapshot['added'])]) }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route($area.'sites.backups.show', [$site, $snapshot['id']]) }}" class="btn btn-sm btn-outline-primary">
                                    <x-icon name="folder-open" class="me-1" />{{ __('backups.list.open') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

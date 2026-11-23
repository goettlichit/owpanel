<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('backups.show.files') }}</h3>
    </div>
    @if (isset($answer['error']))
        <div class="card-body text-danger">{{ __('backups.show.error', ['error' => \Illuminate\Support\Facades\Lang::has('tasks.errors.'.$answer['error']) ? __('tasks.errors.'.$answer['error']) : $answer['error']]) }}</div>
    @elseif ($answer['files'] === [])
        <div class="card-body text-secondary">{{ __('backups.show.empty') }}</div>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('backups.show.name') }}</th>
                        <th scope="col">{{ __('backups.list.size') }}</th>
                        <th scope="col">{{ __('backups.show.changed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($answer['files'] as $file)
                        <tr>
                            <td class="text-break">
                                @if ($file['kind'])
                                    <div class="fw-medium">{{ __('server_backups.panel_files.'.$file['kind']) }}</div>
                                @endif
                                <div @class(['font-monospace small', 'text-secondary' => $file['kind']])>{{ $file['path'] }}</div>
                            </td>
                            <td class="text-nowrap text-secondary">{{ $file['size'] !== null ? \App\Support\Units::bytes($file['size']) : '' }}</td>
                            <td class="text-nowrap text-secondary small">@if ($file['mtime'])<x-time :value="\Illuminate\Support\Carbon::parse($file['mtime'])" />@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

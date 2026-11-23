<div @if ($running) wire:poll.5s @endif>
    @if ($runs->isEmpty())
        <div class="card-body text-secondary">{{ __('snapshots.runs.empty') }}</div>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('snapshots.runs.started') }}</th>
                        <th scope="col">{{ __('snapshots.runs.trigger') }}</th>
                        <th scope="col">{{ __('snapshots.runs.status') }}</th>
                        <th scope="col">{{ __('snapshots.runs.result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr>
                            <td class="text-nowrap"><x-time :value="$run->created_at" /></td>
                            <td>
                                {{-- Same colours as the kinds of backups: automatic blue, by hand green. --}}
                                <span class="badge bg-{{ $run->trigger === 'manual' ? 'green' : 'blue' }}-lt">{{ __('snapshots.triggers.'.$run->trigger) }}</span>
                                @if ($run->user) <span class="text-secondary small">· {{ $run->user->first_name }}</span>@endif
                            </td>
                            <td>
                                {{-- The states of tasks, so "done" reads "Succeeded" as with backups. --}}
                                <x-status-badge :status="['pending' => 'pending', 'creating' => 'running', 'done' => 'succeeded', 'failed' => 'failed'][$run->status] ?? 'unknown'" />
                            </td>
                            <td class="small">
                                @if ($run->status === 'failed')
                                    <span class="text-danger">{{ $run->errorMessage() }}</span>
                                @elseif ($run->status === 'done')
                                    {{ trans_choice('snapshots.runs.deleted', $run->deleted_count, ['count' => $run->deleted_count]) }}
                                    @if ($run->error)<div class="text-warning">{{ $run->errorMessage() }}</div>@endif
                                @else
                                    <span class="text-secondary">{{ __('snapshots.runs.waiting') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($runs->hasPages())
            <div class="card-footer">{{ $runs->links() }}</div>
        @endif
    @endif
</div>

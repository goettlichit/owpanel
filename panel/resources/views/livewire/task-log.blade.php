{{--
    Status and log of a server task (App\Livewire\TaskLog). While the task
    is not finished, wire:poll asks for an update every second. When it
    finishes, the component shows a toast under the header (E-065). The log box
    keeps its scroll position at the bottom by itself (flex column-reverse),
    unless the reader scrolled up.
--}}
<div @unless ($task->isFinished()) wire:poll.1s @endunless>
    <div class="row row-cards">
        <div class="col-lg-6">
            <x-card :title="__('tasks.details.title')"
                :accent="match ($task->status->value) { 'succeeded' => 'green', 'failed' => 'red', default => 'blue' }">
                <x-slot:actions>
                    <x-status-badge :status="$task->status->value" />
                </x-slot:actions>

                <dl class="row mb-0">
                    <dt class="col-sm-5">{{ __('tasks.details.server') }}</dt>
                    <dd class="col-sm-7">
                        <a href="{{ route('servers.show', $task->server) }}">{{ $task->server->display_name }}</a>
                    </dd>

                    @if ($task->site)
                        <dt class="col-sm-5">{{ __('tasks.details.site') }}</dt>
                        <dd class="col-sm-7 text-break">
                            <a href="{{ route('sites.show', $task->site) }}">{{ $task->site->display_domain }}</a>
                        </dd>
                    @endif

                    <dt class="col-sm-5">{{ __('tasks.details.script') }}</dt>
                    <dd class="col-sm-7"><code>{{ $task->script->value }}</code></dd>

                    <dt class="col-sm-5">{{ __('tasks.details.arguments') }}</dt>
                    <dd class="col-sm-7">
                        @if ($task->arguments)
                            <code>{{ implode(' ', $task->arguments) }}</code>
                        @else
                            <span class="text-secondary">–</span>
                        @endif
                    </dd>

                    <dt class="col-sm-5">{{ __('tasks.details.user') }}</dt>
                    <dd class="col-sm-7">{{ $task->user?->name ?? __('tasks.console') }}</dd>

                    @if ($task->after)
                        {{-- A step of a rebuild waits for the one before it (E-106). --}}
                        <dt class="col-sm-5">{{ __('tasks.details.after') }}</dt>
                        <dd class="col-sm-7">
                            <a href="{{ route('tasks.show', $task->after) }}">{{ __('tasks.scripts.'.$task->after->script->value) }}</a>
                        </dd>
                    @endif

                    <dt class="col-sm-5">{{ __('tasks.details.created') }}</dt>
                    <dd class="col-sm-7"><x-time :value="$task->created_at" /></dd>

                    <dt class="col-sm-5">{{ __('tasks.details.started') }}</dt>
                    <dd class="col-sm-7"><x-time :value="$task->started_at" /></dd>

                    <dt class="col-sm-5">{{ __('tasks.details.finished') }}</dt>
                    <dd class="col-sm-7"><x-time :value="$task->finished_at" /></dd>

                    <dt class="col-sm-5">{{ __('tasks.details.duration') }}</dt>
                    <dd class="col-sm-7">
                        @if (($seconds = $task->durationInSeconds()) !== null)
                            {{ __('tasks.seconds', ['count' => $seconds]) }}
                        @else
                            <span class="text-secondary">–</span>
                        @endif
                    </dd>

                    @if ($task->exit_code !== null)
                        <dt class="col-sm-5">{{ __('tasks.details.exit_code') }}</dt>
                        <dd class="col-sm-7"><code>{{ $task->exit_code }}</code></dd>
                    @endif

                    {{-- Where the file of an export is (E-082, E-083). --}}
                    @if ($task->script === \App\Enums\ServerScript::DbExport && $task->status === \App\Enums\TaskStatus::Succeeded && $task->database)
                        <dt class="col-sm-5">{{ __('tasks.details.file') }}</dt>
                        <dd class="col-sm-7">
                            @if (\App\Databases\Transfers::exists($task->transfer_file))
                                <a href="{{ route('databases.transfer', $task->database) }}">{{ __('tasks.details.file_at', ['name' => $task->database->name]) }}</a>
                                <div class="small text-secondary">{{ __('tasks.details.file_until') }} <x-time :value="$task->finished_at->copy()->addHours(\App\Databases\Transfers::KEEP_HOURS)" /></div>
                            @else
                                <span class="text-secondary">{{ __('tasks.details.file_gone') }}</span>
                            @endif
                        </dd>
                    @endif

                    @if ($task->error)
                        <dt class="col-sm-5">{{ __('tasks.error_title') }}</dt>
                        <dd class="col-sm-7 mb-0">
                            <span class="text-danger">{{ $task->errorMessage() }}</span>
                            <div class="small text-secondary"><code>{{ $task->error }}</code></div>
                        </dd>
                    @endif
                </dl>

                @if ($task->status === \App\Enums\TaskStatus::Pending)
                    <p class="text-secondary small mt-3 mb-0">{{ __('tasks.pending_hint') }}</p>
                @elseif (! $task->isFinished())
                    <p class="text-secondary small mt-3 mb-0">{{ __('tasks.live_hint') }}</p>
                @endif
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('tasks.log.title')" :flush="true">
                @if (($task->output ?? '') === '')
                    <div class="card-body text-secondary">{{ __('tasks.log.empty') }}</div>
                @else
                    <div class="ow-log" tabindex="0" aria-label="{{ __('tasks.log.title') }}">
                        <pre>{{ $task->output }}</pre>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</div>

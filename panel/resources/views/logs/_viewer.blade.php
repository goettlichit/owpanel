{{--
    The end of a log as a card (E-086, E-087). Expects $log, $lines,
    $answer (null when there is nothing to read yet, ['error' => …] or
    lines with size and modified) and $url (callable: log, lines => URL).
--}}
<x-card :title="__('logs.kinds.'.$log)" :flush="true">
    <x-slot:actions>
        <div class="d-flex flex-wrap align-items-center gap-2">
            @if (is_array($answer) && ! isset($answer['error']) && ($answer['size'] ?? null) !== null)
                <span class="text-secondary small">
                    {{ __('logs.size', ['size' => \App\Support\Units::bytes($answer['size'])]) }}
                    @if ($answer['modified'] ?? null)
                        · {{ __('logs.modified') }} <x-time :value="\Illuminate\Support\Carbon::createFromTimestamp($answer['modified'])" />
                    @endif
                </span>
            @endif
            <div class="btn-group btn-group-sm" role="group" aria-label="{{ __('logs.lines_label') }}">
                @foreach (\App\Http\Controllers\LogController::LINES as $count)
                    <a href="{{ $url($log, $count) }}"
                        @class(['btn', 'btn-secondary' => $lines === $count, 'btn-outline-secondary' => $lines !== $count])>{{ $count }}</a>
                @endforeach
            </div>
            <a href="{{ $url($log, $lines) }}" class="btn btn-sm btn-outline-primary">
                <x-icon name="refresh" class="me-1" />{{ __('logs.refresh') }}
            </a>
        </div>
    </x-slot:actions>

    @if ($answer === null)
        <div class="card-body text-secondary">{{ __('logs.not_active') }}</div>
    @elseif (isset($answer['error']))
        <div class="card-body d-flex gap-2">
            <x-icon name="alert-circle" class="text-danger mt-1" />
            <div>
                @if (\Illuminate\Support\Facades\Lang::has('logs.errors.'.$answer['error']))
                    {{ __('logs.errors.'.$answer['error']) }}
                @elseif (\Illuminate\Support\Facades\Lang::has('databases.tool.errors.'.$answer['error']))
                    {{ __('databases.tool.errors.'.$answer['error']) }}
                @else
                    {{ __('databases.tool.errors.unknown', ['code' => $answer['error']]) }}
                @endif
            </div>
        </div>
    @elseif ($answer['lines'] === [])
        <div class="card-body text-secondary">{{ __('logs.empty.'.$log) }}</div>
    @else
        <div class="ow-log ow-log-static" data-ow-scroll-end tabindex="0" aria-label="{{ __('logs.kinds.'.$log) }}">
            <pre>{{ implode("\n", $answer['lines']) }}</pre>
        </div>
    @endif
    <x-slot:footer>
        <span class="text-secondary small">{{ __('logs.hints.'.$log) }}</span>
    </x-slot:footer>
</x-card>

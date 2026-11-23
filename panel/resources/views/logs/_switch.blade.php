{{--
    Switch between the logs of one source (E-086, E-087). Expects $kinds
    (log => icon), $log and $url (callable: log, lines => URL), $prefix of
    the language keys.
--}}
<div class="btn-group" role="group" aria-label="{{ __('logs.choose') }}">
    @foreach ($kinds as $key => $icon)
        <a href="{{ $url($key, $lines) }}"
            @class(['btn', 'btn-primary' => $log === $key, 'btn-outline-primary' => $log !== $key])
            @if ($log === $key) aria-current="page" @endif>
            <x-icon :name="$icon" class="me-1" />{{ __('logs.kinds.'.$key) }}
        </a>
    @endforeach
</div>

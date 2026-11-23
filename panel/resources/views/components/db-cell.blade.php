{{--
    One cell from the database tool (E-079): NULL, binary data, cut text or
    a value. Long text is shortened on screen; the title holds more of it.
--}}
@props(['value', 'number' => false])
@if ($value === null)
    <span class="text-secondary fst-italic">{{ __('databases.tool.cell.null') }}</span>
@elseif (is_array($value) && isset($value['binary']))
    <span class="badge bg-secondary-lt" title="0x{{ $value['hex'] }}{{ $value['binary'] > 32 ? '…' : '' }}">{{ __('databases.tool.cell.binary', ['size' => \App\Support\Units::bytes($value['binary'])]) }}</span>
@else
    @php($text = is_array($value) ? $value['text'] : (string) $value)
    <span @class(['ow-db-cell', 'font-monospace' => $number]) @if (mb_strlen($text) > 120) title="{{ mb_strimwidth($text, 0, 2000, '…') }}" @endif>{{ mb_strimwidth($text, 0, 120, '…') }}</span>
    @if (is_array($value))
        <span class="badge bg-orange-lt ms-1">{{ __('databases.tool.cell.cut', ['size' => \App\Support\Units::bytes($value['length'])]) }}</span>
    @endif
@endif

{{--
    Small button that copies a value to the clipboard (resources/js/app.js).
    <x-copy-button :value="$database->name" />
--}}
@props(['value'])
<button type="button" {{ $attributes->class(['btn btn-sm btn-ghost-secondary btn-icon ms-1 align-baseline']) }}
    data-ow-copy="{{ $value }}"
    data-ow-copy-label="{{ __('ui.copy') }}"
    data-ow-copied="{{ __('ui.copied') }}"
    data-ow-copy-failed="{{ __('ui.copy_failed') }}"
    aria-label="{{ __('ui.copy') }}" title="{{ __('ui.copy') }}">
    <x-icon name="copy" />
</button>

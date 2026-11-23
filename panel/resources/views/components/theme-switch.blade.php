{{-- Colour scheme as an icon button: system, light or dark. Small POST forms, works without JavaScript. --}}
@php($current = \App\Support\Preferences::theme())
<div {{ $attributes->class(['dropdown']) }}>
    <button type="button" class="btn ow-icon-button" data-bs-toggle="dropdown" aria-expanded="false"
        aria-label="{{ __('ui.theme.switch') }}: {{ $current->label() }}" title="{{ $current->label() }}">
        <x-icon :name="$current->icon()" />
    </button>
    <div class="dropdown-menu dropdown-menu-end">
        @foreach (\App\Enums\Theme::cases() as $theme)
            <form method="POST" action="{{ route('preferences.theme') }}">
                @csrf
                <button type="submit" name="theme" value="{{ $theme->value }}"
                    @class(['dropdown-item', 'active' => $current === $theme])
                    @if ($current === $theme) aria-current="true" @endif>
                    <x-icon :name="$theme->icon()" class="me-2" />{{ $theme->label() }}
                </button>
            </form>
        @endforeach
    </div>
</div>

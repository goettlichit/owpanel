{{-- Language as an icon button. Each language is its own small POST form with CSRF token. --}}
@php($locales = config('owpanel.locales'))
<div {{ $attributes->class(['dropdown']) }}>
    <button type="button" class="btn ow-icon-button" data-bs-toggle="dropdown" aria-expanded="false"
        aria-label="{{ __('ui.language.switch') }}: {{ $locales[app()->getLocale()] }}" title="{{ $locales[app()->getLocale()] }}">
        <x-icon name="language" />
    </button>
    <div class="dropdown-menu dropdown-menu-end">
        @foreach ($locales as $code => $name)
            <form method="POST" action="{{ route('preferences.locale') }}">
                @csrf
                <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}"
                    @class(['dropdown-item', 'active' => app()->getLocale() === $code])
                    @if (app()->getLocale() === $code) aria-current="true" @endif>
                    {{ $name }}
                </button>
            </form>
        @endforeach
    </div>
</div>

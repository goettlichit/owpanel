{{--
    Layout for the sign-in pages: the same first header row as the main
    layout, with logo, language and appearance (E-066), and one centred card.
--}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-preference="{{ \App\Support\Preferences::theme()->value }}">
<head>
    <x-head :title="$title" />
</head>
<body>
    <a href="#content" class="visually-hidden-focusable">{{ __('ui.skip_to_content') }}</a>
    <div class="page">
        <x-topbar :shadow="true" />

        <div class="ow-guest">
            <main id="content" class="card card-md">
                <div class="card-status-top bg-blue"></div>
                <div class="card-body">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <x-footer />
    </div>
</body>
</html>

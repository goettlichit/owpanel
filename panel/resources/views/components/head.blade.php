{{-- Shared <head> of all layouts. --}}
@props(['title' => null])
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
{{-- Installable on the home screen; iOS shows push messages only then (E-101). --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="theme-color" content="#066fd1">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="OWPanel">
@if (auth()->user()?->isAdmin())
    {{-- Open alerts for the badge on the app icon (E-119). --}}
    <meta name="ow-open-alerts" content="{{ \App\Models\Alert::openCount() }}">
@endif
<title>{{ $title ? $title.' · ' : '' }}{{ __('ui.brand') }}</title>
<script src="{{ asset('js/theme.js') }}"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Page title with optional pretitle and actions on the right. --}}
@props(['title', 'pretitle' => null])
<div class="d-flex flex-wrap align-items-end gap-2 mb-3 d-print-none">
    <div class="me-auto">
        @if ($pretitle)
            <div class="page-pretitle">{{ $pretitle }}</div>
        @endif
        <h1 class="page-title">{{ $title }}</h1>
    </div>
    @isset($actions)
        <div class="btn-list">{{ $actions }}</div>
    @endisset
</div>

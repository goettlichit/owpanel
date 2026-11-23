{{-- Permanent link to the source of the running version (AGPL-3.0-or-later, section 13). --}}
<a href="{{ \App\Support\SourceCode::url() }}" {{ $attributes->class(['link-secondary']) }} target="_blank" rel="noopener noreferrer">
    <x-icon name="brand-github" class="me-1" />{{ __('ui.footer.source_code') }}
</a>

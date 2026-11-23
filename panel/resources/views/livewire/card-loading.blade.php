{{-- Placeholder of a part that is read from the server after the page has loaded. $title makes it a card. --}}
<div @class(['h-100' => isset($title)])>
    @isset($title)
        <x-card :title="$title" class="h-100">
            <div class="d-flex align-items-center gap-2 text-secondary">
                <span class="spinner-border spinner-border-sm" role="status"></span>
                <span>{{ $text ?? __('ui.loading_server') }}</span>
            </div>
        </x-card>
    @else
        <div class="card-body d-flex align-items-center gap-2 text-secondary">
            <span class="spinner-border spinner-border-sm" role="status"></span>
            <span>{{ $text ?? __('ui.loading_server') }}</span>
        </div>
    @endisset
</div>

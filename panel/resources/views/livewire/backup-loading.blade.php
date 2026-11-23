{{-- Placeholder while the server reads from the Storage Box. --}}
<div @class(['card' => $card ?? false])>
    <div class="card-body d-flex align-items-center gap-2 text-secondary">
        <span class="spinner-border spinner-border-sm" role="status"></span>
        <span>{{ $text }}</span>
    </div>
</div>

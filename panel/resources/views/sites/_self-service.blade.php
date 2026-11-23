{{--
    Backups and restores the package still allows today (E-145, E-146).
    Expects $selfService and $block: true for the warning box at the top of
    a card when nothing is possible (like the forms of cron jobs and
    databases), false for the plain line next to the button otherwise.
--}}
@if ($block)
    @if (! $selfService['possible'])
        <div @class(['alert alert-warning', 'mb-3' => $margin ?? true, 'mb-0' => ! ($margin ?? true)]) role="status">
            {{ $selfService['package'] ? __('my.backups.used_up', ['max' => $selfService['max']]) : __('my.backups.no_package') }}
        </div>
    @endif
@elseif ($selfService['possible'])
    <span class="small text-secondary">
        {{ $selfService['max'] === null ? __('my.backups.unlimited') : __('my.backups.left', ['left' => $selfService['max'] - $selfService['used'], 'max' => $selfService['max']]) }}
    </span>
@endif

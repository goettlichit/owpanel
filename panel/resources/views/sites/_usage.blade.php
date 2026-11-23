{{--
    Storage of one website (E-126): files and databases, measured every
    15 minutes. Shared by the administration and the customer area.
--}}
<dt class="col-sm-4">{{ __('storage.title') }}</dt>
<dd class="col-sm-8 {{ $last ?? false ? 'mb-0' : '' }}">
    @if ($site->usageBytes() === null)
        <span class="text-secondary">{{ __('sites.details.not_yet') }}</span>
    @else
        {{ App\Support\Units::bytes($site->usageBytes()) }}
        <div class="small text-secondary">
            {{ __('storage.split', ['files' => App\Support\Units::bytes((int) $site->usage_files_bytes), 'databases' => App\Support\Units::bytes((int) $site->usage_db_bytes)]) }}
        </div>
        @if (($showQuotaNote ?? false) && $site->server?->quota_active === false && $site->customer?->package?->storage_mb !== null)
            <div class="small text-orange">{{ __('storage.quota_unavailable', ['filesystem' => $site->server->storage_filesystem ?: __('storage.quota_unknown')]) }}</div>
        @endif
    @endif
</dd>

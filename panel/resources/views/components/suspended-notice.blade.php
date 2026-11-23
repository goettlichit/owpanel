{{--
    Banner on every page of a suspended website (E-127). Customers see
    neither reason nor note; administrators see who suspended it and when,
    with a link to the page where they lift it.
--}}
@props(['site'])
<div class="alert alert-danger" role="status">
    <div class="d-flex align-items-start gap-2 w-100">
        <x-icon name="lock" class="mt-1" />
        <div class="flex-fill">
            @if (auth()->user()?->isAdmin())
                <div class="fw-medium">{{ __('sites.suspension.banner_admin', ['by' => __('sites.suspension.by.'.($site->suspended_by ?? 'admin'))]) }}
                    <x-time :value="$site->suspended_at" />.</div>
                <div>{{ __('sites.suspension.banner_admin_text') }}</div>
            @else
                <div class="fw-medium">{{ __('my.suspension.banner') }}</div>
                <div>{{ __('my.suspension.banner_text') }}</div>
            @endif
        </div>
        @if (auth()->user()?->isAdmin() && ! request()->routeIs('sites.suspension'))
            <a href="{{ route('sites.suspension', $site) }}" class="btn btn-sm btn-outline-danger">{{ __('sites.suspension.manage') }}</a>
        @endif
    </div>
</div>

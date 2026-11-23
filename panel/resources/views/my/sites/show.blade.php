{{--
    Overview of a website in the customer area (E-124, E-143): domain, www
    and certificate. No server, Linux user or task log; texts address the
    customer with "Sie" (E-132).
--}}
@php
    $active = $site->status === \App\Enums\SiteStatus::Active;
    $wwwName = \App\Sites\Domain::display('www.'.$site->domain);
    $certificate = $site->certificateState();
@endphp
<x-layouts.app :title="$site->display_domain">
    <x-slot:sidebar>
        @include('my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="$site->display_domain" :pretitle="__('my.sites.title')">
        @if ($active)
            <x-slot:actions>
                <a href="{{ ($site->tls ? 'https://' : 'http://').$site->domain }}" class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer">
                    <x-icon name="external-link" class="me-1" />{{ __('sites.encryption.open') }}
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($busy || ! $active || $failed)
        <div @class(['alert', 'alert-info' => $busy || ! $active, 'alert-warning' => ! $busy && $active && $failed]) role="status">
            @if ($busy)
                {{ __('my.sites.state.busy') }}
            @elseif (! $active)
                {{ __('my.sites.state.'.$site->status->value) }}
            @else
                {{ __('my.sites.state.failed') }}
            @endif
        </div>
    @endif

    <div class="row row-cards">
        <div class="col-12">
            <x-card :title="__('sites.details.title')" :accent="$active ? 'green' : 'orange'">
                <x-slot:actions>
                    <x-status-badge :status="$site->badgeStatus()" />
                </x-slot:actions>
                <div class="row">
                    <div class="col-lg-6">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('sites.details.domain') }}</dt>
                            <dd class="col-sm-8 text-break">{{ $site->display_domain }}<x-copy-button :value="$site->display_domain" /></dd>
                            @if ($site->display_domain !== $site->domain)
                                <dt class="col-sm-4">{{ __('sites.details.punycode') }}</dt>
                                <dd class="col-sm-8 text-break"><code>{{ $site->domain }}</code></dd>
                            @endif
                            <dt class="col-sm-4">{{ __('sites.columns.tls') }}</dt>
                            <dd class="col-sm-8">@include('sites._tls')</dd>
                            <dt class="col-sm-4">{{ __('sites.details.created') }}</dt>
                            <dd class="col-sm-8 mb-lg-0"><x-time :value="$site->created_at" /></dd>
                        </dl>
                    </div>
                    <div class="col-lg-6">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('sites.details.php') }}</dt>
                            <dd class="col-sm-8">{{ $site->php_version ?? __('sites.details.not_yet') }}</dd>
                            {{-- Paths as SFTP shows them, not the paths on the server. --}}
                            <dt class="col-sm-4">{{ __('sites.details.directory') }}</dt>
                            <dd class="col-sm-8"><code>/htdocs</code></dd>
                            <dt class="col-sm-4">{{ __('sites.details.document_root') }}</dt>
                            <dd class="col-sm-8"><code>/htdocs/public</code></dd>
                            <dt class="col-sm-4">{{ __('sites.details.databases') }}</dt>
                            <dd class="col-sm-8"><a href="{{ route('my.databases.index', $site) }}">{{ trans_choice('sites.details.databases_count', $site->databases_count, ['count' => $site->databases_count]) }}</a></dd>
                            @include('sites._usage', ['last' => true])
                        </dl>
                    </div>
                </div>
            </x-card>
        </div>

        {{-- Certificate (E-076): request one when the website runs on HTTP only. --}}
        <div class="col-12">
            <x-feature-card :title="__('sites.encryption.title')" :description="__('sites.encryption.description')" :active="$site->tls">
                @if ($site->tls)
                    <p class="mb-1">{{ __('my.sites.certificate.active') }}</p>
                    @if ($site->certificate_expires_at)
                        <p class="mb-0 text-secondary">{{ __('sites.certificate.expires') }}: <x-time :value="$site->certificate_expires_at" /></p>
                    @endif
                    @if ($certificate === 'expiring' || $certificate === 'expired')
                        <p class="mt-2 mb-0 text-warning">{{ __('my.sites.certificate.expiring') }}</p>
                    @endif
                @elseif ($active)
                    {{ __('my.sites.certificate.off') }}
                @else
                    {{ __('my.sites.certificate.pending') }}
                @endif
                @if ($active && $site->canRequestCertificate())
                    @can('requestCertificate', $site)
                        <x-slot:actions>
                            <form data-ow-busy="{{ __('tasks.busy.certificate') }}" data-ow-task="site-create" method="POST" action="{{ route('my.sites.certificate', $site) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary" @disabled($busy)>
                                    <x-icon name="certificate" class="me-1" />{{ __('sites.certificate.request') }}
                                </button>
                            </form>
                        </x-slot:actions>
                    @endcan
                @endif
            </x-feature-card>
        </div>

        {{-- www.<domain> as a second address (E-110); only for main domains (E-120). --}}
        @if ($active && \App\Models\Site::wwwPossible($site->domain))
            @php($wwwOffered = \App\Models\Site::wwwOffered($site->domain))
            <div class="col-12">
                <x-feature-card :class="! $site->www && ! $wwwOffered ? 'opacity-50' : ''" :title="__('sites.www.title')"
                    :description="__('sites.www.description', ['www' => $wwwName, 'domain' => $site->display_domain])" :active="$site->www"
                    :warning="$site->www && $site->tls && ! $site->www_tls ? __('sites.www.badge_no_certificate') : null">
                    @if ($site->www)
                        <p class="mb-0">{{ __('sites.www.active', ['www' => $wwwName, 'domain' => $site->display_domain]) }}</p>
                        @if ($site->tls && ! $site->www_tls && ! $busy)
                            <p class="text-warning mt-2 mb-0">{{ __('my.sites.www.no_certificate', ['www' => $wwwName]) }}</p>
                        @endif
                    @elseif (! $wwwOffered)
                        <span class="text-secondary">{{ __('sites.www.subdomain_off') }}</span>
                    @else
                        {{ __('sites.www.off', ['www' => $wwwName]) }}
                    @endif
                    @can('requestCertificate', $site)
                        <x-slot:actions>
                            <form data-ow-busy="{{ __('tasks.busy.www') }}" data-ow-task="site-create" method="POST" action="{{ route('my.sites.www', $site) }}">
                                @csrf
                                <input type="hidden" name="www" value="{{ $site->www ? 0 : 1 }}">
                                <button type="submit" @class(['btn', 'btn-primary' => ! $site->www, 'btn-outline-secondary' => $site->www]) @disabled($busy || (! $site->www && ! $wwwOffered))>
                                    <x-icon name="{{ $site->www ? 'unlink' : 'link' }}" class="me-1" />{{ $site->www ? __('sites.www.disable') : __('sites.www.enable') }}
                                </button>
                            </form>
                            @if ($site->canRequestWwwCertificate())
                                <form data-ow-busy="{{ __('tasks.busy.certificate') }}" data-ow-task="site-create" method="POST" action="{{ route('my.sites.certificate', $site) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary" @disabled($busy)>
                                        <x-icon name="certificate" class="me-1" />{{ __('sites.www.retry_certificate') }}
                                    </button>
                                </form>
                            @endif
                        </x-slot:actions>
                    @endcan
                </x-feature-card>
            </div>
        @endif
    </div>
</x-layouts.app>

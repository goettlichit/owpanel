<x-layouts.app :title="__('sites.menu.certificate').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.menu.certificate')" :pretitle="$site->display_domain" />

    <div class="row row-cards">
        <div class="col-12">
            @php($certificate = $site->certificateState())
            @php($requesting = $busy && $site->wants_tls && ! $site->tls && $site->status === \App\Enums\SiteStatus::Active)
            @php($requestFailed = ! $busy && ! $site->tls && $site->status === \App\Enums\SiteStatus::Active && in_array($lastTask?->script, [\App\Enums\ServerScript::SiteCreate, \App\Enums\ServerScript::GatewaySite], true) && $lastTask?->status === \App\Enums\TaskStatus::Failed)
            <x-feature-card :title="__('sites.encryption.title')" :description="__('sites.encryption.description')" :active="$site->tls">
                @if ($site->tls)
                    <p class="mb-2">{{ __('sites.encryption.active') }}</p>
                    {{-- Valid until and renewal side by side from lg on. --}}
                    <dl class="row mb-0">
                        <dt class="col-sm-4 col-lg-2">{{ __('sites.certificate.expires') }}</dt>
                        <dd class="col-sm-8 col-lg-4 mb-1 text-body">
                            @if ($site->certificate_expires_at)
                                <x-time :value="$site->certificate_expires_at" />
                                @if ($certificate !== 'valid')
                                    <span @class(['badge ms-1', 'bg-red-lt' => $certificate === 'expired', 'bg-orange-lt' => $certificate === 'expiring'])>
                                        {{ __('sites.certificate.state.'.$certificate) }}
                                    </span>
                                @endif
                                <div class="small text-secondary">{{ $site->certificate_expires_at->locale(app()->getLocale())->diffForHumans() }}</div>
                            @else
                                {{ __('sites.certificate.unknown') }}
                            @endif
                        </dd>
                        <dt class="col-sm-4 col-lg-2">{{ __('sites.certificate.renewal') }}</dt>
                        <dd class="col-sm-8 col-lg-4 mb-0 text-body">
                            @if ($site->server->certificate_renewal === null)
                                {{ __('sites.certificate.unknown') }}
                            @elseif ($site->server->certificate_renewal)
                                {{ __('sites.certificate.renewal_on') }}
                            @else
                                <span class="text-danger">{{ __('sites.certificate.renewal_off') }}</span>
                            @endif
                        </dd>
                    </dl>
                    @if ($certificate === 'expiring' || $certificate === 'expired')
                        <p class="small mt-2 mb-0">{{ __('sites.certificate.warning') }}</p>
                    @endif
                @elseif ($requesting)
                    {{ __('sites.certificate.requesting') }}
                @elseif ($site->status === \App\Enums\SiteStatus::Pending && $site->wants_tls)
                    {{ __('sites.encryption.pending') }}
                @elseif ($site->status === \App\Enums\SiteStatus::Failed && $site->wants_tls)
                    {{ __('sites.encryption.failed') }}
                @else
                    @if ($requestFailed)
                        <p class="text-danger mb-2">{{ __('sites.certificate.failed', ['error' => $lastTask->errorMessage()]) }}</p>
                    @endif
                    {{ __('sites.encryption.off') }}
                @endif

                @if ($site->canRequestCertificate())
                    @can('requestCertificate', $site)
                        <x-slot:actions>
                            <form data-ow-busy="{{ __('tasks.busy.certificate') }}" data-ow-task="site-create gateway-site" method="POST" action="{{ route('sites.certificate', $site) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary">
                                    <x-icon name="certificate" class="me-1" />{{ __('sites.certificate.request') }}
                                </button>
                            </form>
                        </x-slot:actions>
                    @endcan
                @endif
            </x-feature-card>
        </div>
    </div>
</x-layouts.app>

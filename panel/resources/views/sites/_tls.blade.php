{{--
    Encryption of a website as badges: HTTPS and HTTP/3 always go together
    (docs/ARCHITEKTUR.md, "HTTP/3 und QUIC"), plus a warning when the
    certificate expires within 20 days (E-076). Expects $site.
--}}
@if ($site->tls)
    <x-badge tone="ok"><x-icon name="lock" class="me-1" />{{ __('sites.tls.https') }}</x-badge>
    <x-badge tone="ok"><x-icon name="bolt" class="me-1" />{{ __('sites.tls.http3') }}</x-badge>
    @if (in_array($site->certificateState(), ['expiring', 'expired'], true))
        <div class="mt-1">
            <span @class(['badge', 'bg-red-lt' => $site->certificateState() === 'expired', 'bg-orange-lt' => $site->certificateState() === 'expiring'])>
                <x-icon name="certificate" class="me-1" />{{ __('sites.certificate.expiring_badge', ['date' => $site->certificate_expires_at->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L')]) }}
            </span>
        </div>
    @endif
@elseif ($site->status === \App\Enums\SiteStatus::Active)
    <x-badge tone="neutral"><x-icon name="lock-open" class="me-1" />{{ __('sites.tls.none') }}</x-badge>
@else
    <span class="text-secondary">–</span>
@endif

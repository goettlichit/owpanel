<x-layouts.app :title="__('sites.menu.domain').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.menu.domain')" :pretitle="$site->display_domain" />

    <div class="row row-cards">
        {{-- Website and domain: domain, www address and customer (E-110, E-112, E-137). --}}
        @if ($site->status !== \App\Enums\SiteStatus::Active)
            <div class="col-12">
                <x-card>
                    <p class="text-secondary mb-0">{{ __('sites.menu.not_active') }}</p>
                </x-card>
            </div>
        @endif
        @if ($site->status === \App\Enums\SiteStatus::Active)
            @can('changeDomain', $site)
                {{-- Move the website to another domain (E-112). --}}
                <div class="col-12">
                    <form data-ow-busy="{{ __('tasks.busy.domain') }}" data-ow-task="site-create" method="POST" action="{{ route('sites.domain', $site) }}" class="h-100" novalidate>
                        @csrf
                        <x-card :title="__('sites.domain.title')" class="h-100">
                            <p class="text-secondary">{{ __('sites.domain.text', ['domain' => $site->display_domain]) }}</p>
                            <div class="mb-0">
                                <label class="form-label required" for="new_domain">{{ __('sites.domain.label') }}</label>
                                <input type="text" id="new_domain" name="new_domain" value="{{ old('new_domain') }}" required
                                    maxlength="253" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="url"
                                    placeholder="example.de" aria-describedby="new-domain-hint"
                                    @class(['form-control', 'is-invalid' => $errors->has('new_domain')])>
                                @error('new_domain')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-hint" id="new-domain-hint">{{ __('sites.domain.dns') }}</div>
                            </div>
                            <x-slot:footer>
                                <button type="submit" class="btn btn-primary" @disabled($busy)>
                                    <x-icon name="arrows-exchange" class="me-1" />{{ __('sites.domain.submit') }}
                                </button>
                            </x-slot:footer>
                        </x-card>
                    </form>
                </div>
            @endcan
        @endif

        @if ($site->status === \App\Enums\SiteStatus::Active && \App\Models\Site::wwwPossible($site->domain))
            {{-- Only main domains get www (E-120); for subdomains the card stays greyed out unless www is already on. --}}
            @php($wwwOffered = \App\Models\Site::wwwOffered($site->domain))
            {{-- www.<domain> as a second address that sends to the main one (E-110). --}}
            @php($wwwName = \App\Sites\Domain::display('www.'.$site->domain))
            <div class="col-12">
                {{-- With a certificate for the domain but not for www, www only works over HTTP and only with a DNS entry: orange. --}}
                <x-feature-card :class="! $site->www && ! $wwwOffered ? 'opacity-50' : ''" :title="__('sites.www.title')" :description="__('sites.www.description', ['www' => $wwwName, 'domain' => $site->display_domain])" :active="$site->www"
                    :warning="$site->www && $site->tls && ! $site->www_tls ? __('sites.www.badge_no_certificate') : null">
                    @if ($site->www)
                        <p class="mb-2">{{ __('sites.www.active', ['www' => $wwwName, 'domain' => $site->display_domain]) }}</p>
                        @if ($site->tls && ! $site->www_tls && ! $busy)
                            <p class="text-warning mb-0">{{ __('sites.www.no_certificate', ['www' => $wwwName]) }}</p>
                        @elseif ($site->tls && $site->www_tls)
                            <p class="mb-0">{{ __('sites.www.certificate') }}</p>
                        @endif
                    @elseif (! $wwwOffered)
                        <span class="text-secondary">{{ __('sites.www.subdomain_off') }}</span>
                    @else
                        {{ __('sites.www.off', ['www' => $wwwName]) }}
                    @endif

                    @can('requestCertificate', $site)
                        <x-slot:actions>
                            <form data-ow-busy="{{ __('tasks.busy.www') }}" data-ow-task="site-create" method="POST" action="{{ route('sites.www', $site) }}">
                                @csrf
                                <input type="hidden" name="www" value="{{ $site->www ? 0 : 1 }}">
                                <button type="submit" @class(['btn', 'btn-primary' => ! $site->www, 'btn-outline-secondary' => $site->www]) @disabled($busy || (! $site->www && ! $wwwOffered))>
                                    <x-icon name="{{ $site->www ? 'unlink' : 'link' }}" class="me-1" />{{ $site->www ? __('sites.www.disable') : __('sites.www.enable') }}
                                </button>
                            </form>
                            @if ($site->canRequestWwwCertificate())
                                <form data-ow-busy="{{ __('tasks.busy.certificate') }}" data-ow-task="site-create" method="POST" action="{{ route('sites.certificate', $site) }}">
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
        {{-- Owner of the website (E-084, E-139); changes nothing on the server. --}}
        @can('assignCustomer', $site)
            <div class="col-12">
                <form method="POST" action="{{ route('sites.customer', $site) }}" class="h-100" novalidate>
                    @csrf
                    @method('PATCH')
                    <x-card :title="__('sites.customer.title')" class="h-100">
                        <p class="text-secondary">{{ __('sites.customer.text') }}</p>
                        @include('sites._owner-select', ['selected' => (string) old('customer', match ($site->owner()) {
                            \App\Models\Site::OWNER_CUSTOMER => $site->customer_id,
                            \App\Models\Site::OWNER_ADMIN => \App\Models\Site::OWNER_ADMIN,
                            default => '',
                        })])
                        @if ($customers->isEmpty())
                            <div class="form-hint"><a href="{{ route('customers.create') }}">{{ __('sites.customer.create_first') }}</a></div>
                        @endif
                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="device-floppy" class="me-1" />{{ __('sites.customer.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endcan
    </div>
</x-layouts.app>

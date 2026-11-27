<x-layouts.app :title="$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="$site->display_domain" :pretitle="__('ui.nav.sites')">
        @if ($site->status === \App\Enums\SiteStatus::Active)
            <x-slot:actions>
                <a href="{{ ($site->tls ? 'https://' : 'http://').$site->domain }}" class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer">
                    <x-icon name="external-link" class="me-1" />{{ __('sites.encryption.open') }}
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- Lasting state of the site as a banner (docs/DESIGN.md, "Hinweise"). --}}
    @if (in_array($site->status, [\App\Enums\SiteStatus::Pending, \App\Enums\SiteStatus::Deleting, \App\Enums\SiteStatus::Failed], true))
        @php($failed = $site->status === \App\Enums\SiteStatus::Failed)
        <div @class(['alert', 'alert-danger' => $failed, 'alert-info' => ! $failed]) role="status">
            <div class="d-flex align-items-start gap-2 w-100">
                <x-icon :name="$failed ? 'alert-triangle' : 'loader-2'" class="mt-1" />
            <div class="flex-fill">
                @if ($failed)
                    {{ __($lastTask?->removesSite() ? 'sites.state.failed_delete' : 'sites.state.failed_create',
                        ['error' => $lastTask?->errorMessage() ?? '–']) }}
                @else
                    {{ __('sites.state.'.$site->status->value) }}
                @endif
            </div>
            @if ($lastTask)
                <a href="{{ route('tasks.show', $lastTask) }}" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                    <x-icon name="list-check" class="me-1" />{{ __('sites.state.show_task') }}
                </a>
            @endif
            </div>
        </div>
    @endif

    <div class="row row-cards">
        <div class="col-12">
            <x-card :title="__('sites.details.title')" class="h-100"
                :accent="match ($site->status) { \App\Enums\SiteStatus::Active => 'green', \App\Enums\SiteStatus::Failed => 'red', default => 'orange' }">
                <x-slot:actions>
                    <x-status-badge :status="$site->badgeStatus()" />
                </x-slot:actions>

                {{-- Two columns from lg on (domain and kind left, server and paths right); stacked on small screens. --}}
                <div class="row">
                    <div class="col-lg-6">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('sites.details.domain') }}</dt>
                            <dd class="col-sm-8 text-break">
                                {{ $site->display_domain }}<x-copy-button :value="$site->display_domain" />
                                @if ($site->www)
                                    <div class="small text-secondary">{{ __('sites.www.description', ['www' => \App\Sites\Domain::display('www.'.$site->domain), 'domain' => $site->display_domain]) }}</div>
                                    @if ($site->tls && ! $site->www_tls)
                                        <div class="small text-warning">{{ __('sites.www.detail_no_certificate') }}</div>
                                    @endif
                                @endif
                            </dd>

                            @if ($site->display_domain !== $site->domain)
                                <dt class="col-sm-4">{{ __('sites.details.punycode') }}</dt>
                                <dd class="col-sm-8 text-break"><code>{{ $site->domain }}</code><x-copy-button :value="$site->domain" /></dd>
                            @endif

                            <dt class="col-sm-4">{{ __('sites.customer.label') }}</dt>
                            <dd class="col-sm-8">
                                @if ($site->customer)
                                    <a href="{{ route('customers.show', $site->customer) }}">{{ $site->customer->display_name }} <span class="text-secondary small">{{ $site->customer->display_number }}</span></a>
                                    <div class="small text-secondary">{{ $site->customer->package?->name ?? __('packages.note.no_package') }}</div>
                                @else
                                    @include('sites._owner')
                                    @if ($site->owner() === \App\Models\Site::OWNER_UNASSIGNED)
                                        <div class="small text-secondary">{{ __('packages.note.unassigned') }}</div>
                                    @endif
                                @endif
                            </dd>

                            <dt class="col-sm-4">{{ __('sites.details.type') }}</dt>
                            <dd class="col-sm-8">{{ __('sites.form.type_php') }}</dd>

                            <dt class="col-sm-4">{{ __('sites.details.php') }}</dt>
                            <dd class="col-sm-8">{{ $site->php_version ?? __('sites.details.not_yet') }}</dd>

                            <dt class="col-sm-4">{{ __('sites.details.created') }}</dt>
                            <dd class="col-sm-8"><x-time :value="$site->created_at" /></dd>
                        </dl>
                    </div>
                    <div class="col-lg-6">
                        <dl class="row mb-0">
                            @if ($showServer)
                                <dt class="col-sm-4">{{ __('sites.details.server') }}</dt>
                                <dd class="col-sm-8"><a href="{{ route('servers.show', $site->server) }}">{{ $site->server->display_name }}</a></dd>
                            @endif

                            <dt class="col-sm-4">{{ __('sites.details.user') }}</dt>
                            <dd class="col-sm-8"><code>{{ $site->system_user }}</code><x-copy-button :value="$site->system_user" /></dd>

                            <dt class="col-sm-4">{{ __('sites.details.directory') }}</dt>
                            <dd class="col-sm-8 text-break"><code>{{ $site->root_path }}/htdocs</code><x-copy-button :value="$site->root_path.'/htdocs'" /></dd>

                            <dt class="col-sm-4">{{ __('sites.details.document_root') }}</dt>
                            <dd class="col-sm-8 text-break"><code>{{ $site->root_path }}/htdocs/public</code><x-copy-button :value="$site->root_path.'/htdocs/public'" /></dd>

                            <dt class="col-sm-4">{{ __('sites.details.databases') }}</dt>
                            <dd class="col-sm-8">
                                <a href="{{ route('databases.index', $site) }}">{{ trans_choice('sites.details.databases_count', $site->databases_count, ['count' => $site->databases_count]) }}</a>
                            </dd>

                            @include('sites._usage', ['showQuotaNote' => true])
                        </dl>
                    </div>
                </div>
            </x-card>
        </div>

        @if ($site->canRetryCreate())
            @can('retry', $site)
                <div class="col-12">
                    <form data-ow-busy="{{ __('tasks.busy.retry') }}" data-ow-task="site-create gateway-site" method="POST" action="{{ route('sites.retry', $site) }}" class="h-100">
                        @csrf
                        <x-card :title="__('sites.retry.title')" tone="info" class="h-100">
                            <p class="text-secondary mb-0">{{ __('sites.retry.text') }}</p>
                            <x-slot:footer>
                                <button type="submit" class="btn btn-primary">
                                    <x-icon name="refresh" class="me-1" />{{ __('sites.retry.submit') }}
                                </button>
                            </x-slot:footer>
                        </x-card>
                    </form>
                </div>
            @endcan
        @endif
    </div>
</x-layouts.app>

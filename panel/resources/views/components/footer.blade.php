{{--
    Footer as a band of its own (E-067). Administrators see three columns:
    a short description, help links and a few system facts. Everybody, also
    before sign-in, sees the bottom line with version, licence and the link
    to the source code (AGPL-3.0-or-later, section 13).
--}}
@php($isAdmin = auth()->user()?->isAdmin() ?? false)
@php($homepage = rtrim((string) config('owpanel.homepage_url'), '/'))
@php($docs = $homepage !== '' ? $homepage.(app()->getLocale() === 'en' ? '/en' : '').'/docs/' : null)
{{-- Customers get "Help": the provider's own page or the customer documentation (E-178). --}}
@php($help = (auth()->user()?->isCustomer() ?? false) ? (\App\Models\Setting::get(\App\Models\Setting::HELP_URL) ?: ($docs ? $docs.'customer-start/' : null)) : null)
<footer class="ow-footer d-print-none">
    @if ($isAdmin)
        <div class="ow-footer-columns">
            <section>
                <h2 class="ow-footer-title"><x-icon name="info-circle" />{{ __('ui.footer.about.title') }}</h2>
                <p class="text-secondary mb-0">{{ __('ui.footer.about.text') }}</p>
            </section>
            <section>
                <h2 class="ow-footer-title"><x-icon name="lifebuoy" />{{ __('ui.footer.help.title') }}</h2>
                <ul class="list-unstyled mb-0">
                    {{-- The administrator documentation of the website in the language of the panel (E-183), else docs/ in the source (E-109). --}}
                    <li><a href="{{ $docs ? $docs.'admin/' : \App\Support\SourceCode::file('docs') }}" class="link-secondary" target="_blank" rel="noopener noreferrer">{{ __('ui.footer.help.docs') }}</a></li>
                    <li><a href="{{ \App\Support\SourceCode::file('CHANGELOG.md') }}" class="link-secondary" target="_blank" rel="noopener noreferrer">{{ __('ui.footer.help.changelog') }}</a></li>
                    <li><a href="{{ \App\Support\SourceCode::issues() }}" class="link-secondary" target="_blank" rel="noopener noreferrer">{{ __('ui.footer.help.issues') }}</a></li>
                </ul>
            </section>
            <section>
                <h2 class="ow-footer-title"><x-icon name="server-cog" />{{ __('ui.footer.system.title') }}</h2>
                <dl class="ow-footer-facts mb-0">
                    <dt>{{ __('ui.footer.system.version') }}</dt>
                    <dd>{{ \App\Support\SourceCode::version() }}</dd>
                    <dt>{{ __('ui.footer.system.php') }}</dt>
                    <dd>{{ PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION }}</dd>
                    <dt>{{ __('ui.footer.system.timezone') }}</dt>
                    <dd>{{ config('owpanel.timezone') }}</dd>
                </dl>
            </section>
        </div>
    @endif
    <div class="ow-footer-bottom">
        <ul class="list-inline list-inline-dots mb-0">
            <li class="list-inline-item">
                @if (config('owpanel.homepage_url'))
                    <a href="{{ config('owpanel.homepage_url') }}" class="link-secondary" target="_blank" rel="noopener noreferrer">OWPanel</a>
                @else
                    OWPanel
                @endif
                {{ __('ui.footer.by') }}
                <a href="{{ config('owpanel.vendor_url') }}" class="link-secondary" target="_blank" rel="noopener noreferrer">goettlichIT</a>
            </li>
            @if ($help)
                <li class="list-inline-item"><a href="{{ $help }}" class="link-secondary" target="_blank" rel="noopener noreferrer">{{ __('ui.footer.help.title') }}</a></li>
            @endif
            <li class="list-inline-item">{{ __('ui.footer.version', ['version' => \App\Support\SourceCode::version()]) }}</li>
            <li class="list-inline-item"><x-source-link /></li>
            <li class="list-inline-item">{{ __('ui.footer.license') }}</li>
            {{-- The provider's legal pages, from System -> Settings (E-091). --}}
            @if ($imprint = \App\Models\Setting::get(\App\Models\Setting::IMPRINT_URL))
                <li class="list-inline-item"><a href="{{ $imprint }}" class="link-secondary" target="_blank" rel="noopener noreferrer">{{ __('ui.footer.imprint') }}</a></li>
            @endif
            @if ($privacy = \App\Models\Setting::get(\App\Models\Setting::PRIVACY_URL))
                <li class="list-inline-item"><a href="{{ $privacy }}" class="link-secondary" target="_blank" rel="noopener noreferrer">{{ __('ui.footer.privacy') }}</a></li>
            @endif
        </ul>
    </div>
</footer>

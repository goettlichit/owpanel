{{--
    Pocket ID of the customer (E-130, E-138). Expects $customer, $access,
    $pocketApi (API key stored) and $identity (link or null).
--}}
@php
    $name = App\Oidc\OidcSettings::name();
    $search = session('pocket_id.search');
    $link = session('pocket_id.link');
@endphp
<x-card :title="$name" :accent="$identity ? 'green' : 'blue'">
    <x-slot:actions>
        @if ($identity)
            <x-badge tone="ok">{{ __('customers.pocket_id.linked') }}</x-badge>
        @elseif ($access->oidc_allowed)
            <x-badge tone="info">{{ __('customers.pocket_id.allowed') }}</x-badge>
        @else
            <x-badge tone="neutral">{{ __('customers.pocket_id.off') }}</x-badge>
        @endif
    </x-slot:actions>

    @if ($link)
        <div class="alert alert-warning" role="status">
            <div class="fw-medium mb-1">{{ __('customers.pocket_id.link_title') }}</div>
            <code class="user-select-all text-break">{{ $link }}</code><x-copy-button :value="$link" />
            <div class="small text-secondary mt-1">{{ __('customers.pocket_id.link_hint', ['hours' => App\Http\Controllers\CustomerPocketIdController::LINK_HOURS]) }}</div>
        </div>
    @endif

    @if ($identity)
        <p class="text-secondary">{{ __('customers.pocket_id.linked_text', ['name' => $name, 'email' => $identity->email ?? $access->email]) }}</p>
        <div class="d-flex flex-wrap gap-2">
            @if ($pocketApi)
                <form method="POST" action="{{ route('customers.pocket_id.login_link', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm"><x-icon name="link" class="me-1" />{{ __('customers.pocket_id.login_link') }}</button>
                </form>
                <form method="POST" action="{{ route('customers.pocket_id.send_link', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm"><x-icon name="mail-forward" class="me-1" />{{ __('customers.pocket_id.send_link') }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('customers.pocket_id.unlink', $customer) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-secondary btn-sm"><x-icon name="unlink" class="me-1" />{{ __('customers.pocket_id.unlink') }}</button>
            </form>
        </div>
    @elseif (! $access->oidc_allowed && ! $pocketApi)
        <p class="text-secondary">{{ __('customers.pocket_id.off_text', ['name' => $name]) }}</p>
        <p class="small text-secondary mb-0">{{ __('customers.pocket_id.no_api_hint') }} <a href="{{ route('settings.services') }}">{{ __('settings.services.title') }}</a></p>
    @else
        <p class="text-secondary">{{ __($pocketApi ? 'customers.pocket_id.api_text' : 'customers.pocket_id.self_text', ['name' => $name]) }}</p>
        @if ($pocketApi)
            @if (is_array($search) && $search['found'])
                {{-- Found by address: link only after the administrator confirmed (E-130). --}}
                <div class="border rounded p-2 mb-2">
                    <div class="fw-medium">{{ trim($search['found']['firstName'].' '.$search['found']['lastName']) ?: $search['found']['username'] }}</div>
                    <div class="small text-secondary">{{ $search['found']['username'] }} · {{ $search['found']['email'] }}</div>
                </div>
                <form method="POST" action="{{ route('customers.pocket_id.link', $customer) }}">
                    @csrf
                    <input type="hidden" name="pocket_id_user" value="{{ $search['found']['id'] }}">
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="link" class="me-1" />{{ __('customers.pocket_id.link_found') }}</button>
                </form>
            @elseif (is_array($search))
                <p class="small">{{ __('customers.pocket_id.not_found', ['name' => $name, 'email' => $access->email]) }}</p>
                <form method="POST" action="{{ route('customers.pocket_id.create', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="user-plus" class="me-1" />{{ __('customers.pocket_id.create', ['name' => $name]) }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('customers.pocket_id.search', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm"><x-icon name="search" class="me-1" />{{ __('customers.pocket_id.search', ['name' => $name]) }}</button>
                </form>
            @endif
        @else
            <p class="small text-secondary mb-0">{{ __('customers.pocket_id.no_api_hint') }} <a href="{{ route('settings.services') }}">{{ __('settings.services.title') }}</a></p>
        @endif
    @endif

    <x-slot:footer>
        <form method="POST" action="{{ route('customers.pocket_id.allow', $customer) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="allowed" value="{{ $access->oidc_allowed ? 0 : 1 }}">
            @if ($access->oidc_allowed)
                <button type="submit" class="btn btn-outline-secondary"><x-icon name="fingerprint-off" class="me-1" />{{ __('customers.pocket_id.revoke', ['name' => $name]) }}</button>
            @else
                <button type="submit" class="btn btn-outline-primary"><x-icon name="fingerprint" class="me-1" />{{ __('customers.pocket_id.allow', ['name' => $name]) }}</button>
            @endif
        </form>
    </x-slot:footer>
</x-card>

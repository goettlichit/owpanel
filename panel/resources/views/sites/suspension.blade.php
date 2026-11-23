<x-layouts.app :title="__('sites.suspension.title').' · '.$site->display_domain">
    <x-slot:sidebar>
        @include('sites._site-menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.suspension.title')" :pretitle="$site->display_domain" />

    {{-- Suspend with an internal note, or lift the suspension (E-127). --}}
    @php($failed = $lastTask?->status === \App\Enums\TaskStatus::Failed)
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            @if ($site->isSuspended())
                <x-card :title="__('sites.suspension.active_title')" tone="danger">
                    <dl class="row mb-3">
                        <dt class="col-sm-4">{{ __('sites.suspension.since') }}</dt>
                        <dd class="col-sm-8"><x-time :value="$site->suspended_at" /></dd>
                        <dt class="col-sm-4">{{ __('sites.suspension.by_label') }}</dt>
                        <dd class="col-sm-8">{{ __('sites.suspension.by.'.($site->suspended_by ?? 'admin')) }}</dd>
                        <dt class="col-sm-4">{{ __('sites.suspension.note') }}</dt>
                        <dd class="col-sm-8 text-break">
                            @if ($site->suspend_note)
                                {!! nl2br(e($site->suspend_note)) !!}
                            @else
                                <span class="text-secondary">{{ __('sites.suspension.no_note') }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('sites.suspension.access_page') }}</dt>
                        <dd class="col-sm-8 mb-0">
                            @if ($account === null)
                                <span class="text-secondary">{{ __('sites.suspension.no_account') }}</span>
                            @else
                                @if ($account->isLocked())
                                    {{ __('sites.suspension.access_locked') }}
                                @endif
                                <a href="{{ route('customers.access', $site->customer) }}">{{ __('sites.suspension.access_page') }}</a>
                            @endif
                        </dd>
                    </dl>
                    @if ($failed && $lastTask->script === \App\Enums\ServerScript::SiteSuspend)
                        <div class="alert alert-warning mb-0" role="status">{{ __('sites.suspension.last_failed') }}</div>
                    @endif
                    <p class="text-secondary mt-3 mb-0">{{ __('sites.suspension.unsuspend_text') }}</p>

                    <x-slot:footer>
                        @if ($running === \App\Enums\ServerScript::SiteSuspend)
                            <form method="POST" action="{{ route('sites.suspension.store', $site) }}" data-ow-task="site-suspend" data-ow-busy="{{ __('sites.suspension.working') }}">
                                @csrf
                                <button type="submit" class="btn btn-danger" disabled><x-icon name="lock" class="me-1" />{{ __('sites.suspension.submit') }}</button>
                            </form>
                        @else
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('sites.suspension.destroy', $site) }}" data-ow-task="site-unsuspend" data-ow-busy="{{ __('sites.suspension.unsuspend_working') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-primary"><x-icon name="lock-open" class="me-1" />{{ __('sites.suspension.unsuspend') }}</button>
                            </form>
                            @if ($failed && $lastTask->script === \App\Enums\ServerScript::SiteSuspend)
                                <form method="POST" action="{{ route('sites.suspension.store', $site) }}" data-ow-task="site-suspend" data-ow-busy="{{ __('sites.suspension.working') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger">{{ __('sites.suspension.retry') }}</button>
                                </form>
                            @endif
                        </div>
                        @endif
                    </x-slot:footer>
                </x-card>
            @elseif ($running === \App\Enums\ServerScript::SiteUnsuspend)
                <x-card :title="__('sites.suspension.active_title')" tone="danger">
                    <p class="text-secondary mb-0">{{ __('sites.suspension.unsuspend_text') }}</p>
                    <x-slot:footer>
                        <form method="POST" action="{{ route('sites.suspension.destroy', $site) }}" data-ow-task="site-unsuspend" data-ow-busy="{{ __('sites.suspension.unsuspend_working') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-primary" disabled><x-icon name="lock-open" class="me-1" />{{ __('sites.suspension.unsuspend') }}</button>
                        </form>
                    </x-slot:footer>
                </x-card>
            @else
                <form method="POST" action="{{ route('sites.suspension.store', $site) }}" novalidate data-ow-task="site-suspend" data-ow-busy="{{ __('sites.suspension.working') }}">
                    @csrf
                    <x-card :title="__('sites.suspension.title')" tone="danger">
                        @if ($failed && $lastTask->script === \App\Enums\ServerScript::SiteUnsuspend)
                            <div class="alert alert-warning" role="status">{{ __('sites.suspension.last_failed') }}</div>
                        @endif
                        <div class="mb-3">
                            <label class="form-label" for="note">{{ __('sites.suspension.note') }}</label>
                            <textarea id="note" name="note" rows="3" maxlength="1000" @class(['form-control', 'is-invalid' => $errors->has('note')])>{{ old('note') }}</textarea>
                            <div class="form-hint">{{ __('sites.suspension.note_hint') }}</div>
                            @error('note')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        @if ($account === null)
                            <p class="text-secondary mb-0">{{ __('sites.suspension.no_account') }}</p>
                        @elseif ($account->isLocked())
                            <p class="text-secondary mb-0">{{ __('sites.suspension.access_locked') }}</p>
                        @else
                            <label class="form-check mb-0">
                                <input type="checkbox" name="lock_access" value="1" class="form-check-input" @checked(old('lock_access'))>
                                <span class="form-check-label">{{ __('sites.suspension.lock_access') }}</span>
                                <span class="form-check-description">{{ __('sites.suspension.lock_access_hint') }}</span>
                            </label>
                        @endif

                        <x-slot:footer>
                            <button type="submit" class="btn btn-danger" @disabled($site->status !== \App\Enums\SiteStatus::Active || $site->isBusy())>
                                <x-icon name="lock" class="me-1" />{{ __('sites.suspension.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            @endif
        </div>
        <div class="col-lg-4">
            <x-card :title="__('sites.suspension.title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('sites.suspension.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>

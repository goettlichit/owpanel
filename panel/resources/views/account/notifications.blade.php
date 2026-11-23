<x-layouts.app :title="__('notifications.title')">
    <x-slot:sidebar>
        <x-account-menu />
    </x-slot:sidebar>

    <x-page-header :title="__('notifications.title')" :pretitle="__('ui.nav.account')" />

    <div class="row row-cards">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('account.notifications.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('notifications.title')" :flush="true">
                    <div class="card-body">
                        <p class="text-secondary mb-0">{{ __('notifications.intro') }}</p>
                        @unless ($mailReady)
                            <p class="small text-warning mt-2 mb-0"><x-icon name="alert-triangle" class="me-1" />{{ __('notifications.mail_missing') }}</p>
                        @endunless
                        @if ($devices->isEmpty())
                            <p class="small text-secondary mt-2 mb-0"><x-icon name="info-circle" class="me-1" />{{ __('notifications.push_missing') }}</p>
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>{{ __('notifications.table.event') }}</th>
                                    @foreach (\App\Notify\Channel::cases() as $channel)
                                        <th class="w-1 text-center">{{ __('notifications.channels.'.$channel->value) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            @foreach ($groups as $group => $events)
                                <tbody>
                                    <tr>
                                        <th colspan="3" class="bg-body-tertiary text-secondary small text-uppercase">{{ __('notifications.groups.'.$group) }}</th>
                                    </tr>
                                    @foreach ($events as $event)
                                        <tr>
                                            <td>
                                                <div>{{ $event->label() }}</div>
                                                <div class="small text-secondary">{{ __('notifications.events.'.$event->value.'.hint') }}</div>
                                            </td>
                                            @foreach (\App\Notify\Channel::cases() as $channel)
                                                <td class="text-center">
                                                    <label class="form-check form-switch m-0 d-inline-block">
                                                        <input type="checkbox" class="form-check-input" value="1"
                                                            name="events[{{ $event->value }}][{{ $channel->value }}]"
                                                            aria-label="{{ $event->label() }} · {{ __('notifications.channels.'.$channel->value) }}"
                                                            @checked($user->wantsNotification($event, $channel))>
                                                    </label>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                        </table>
                    </div>
                    <div class="card-body border-top">
                        <h3 class="card-title mb-3">{{ __('notifications.quiet.title') }}</h3>
                        <label class="form-check form-switch mb-3">
                            <input type="checkbox" class="form-check-input" name="quiet_enabled" value="1" @checked(old('quiet_enabled', $user->quiet_enabled))>
                            <span class="form-check-label">{{ __('notifications.quiet.enabled') }}</span>
                        </label>
                        <div class="row g-3">
                            @foreach (['quiet_from' => 'from', 'quiet_until' => 'until'] as $field => $label)
                                <div class="col-6">
                                    <label class="form-label" for="{{ $field }}">{{ __('notifications.quiet.'.$label) }}</label>
                                    <input type="time" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $user->$field) }}"
                                        @class(['form-control', 'is-invalid' => $errors->has($field)])>
                                    @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endforeach
                        </div>
                        <div class="form-hint mt-2">{{ __('notifications.quiet.hint', ['zone' => config('owpanel.timezone')]) }}</div>
                    </div>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('notifications.save') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        <div class="col-lg-5 d-flex flex-column gap-3">
            <x-card :title="__('notifications.push.title')" :flush="true">
                <div class="card-body" data-ow-push
                    data-vapid-key="{{ $vapidKey }}"
                    data-subscribe-url="{{ route('account.notifications.subscribe') }}"
                    data-csrf="{{ csrf_token() }}"
                    data-enabling="{{ __('notifications.push.enabling') }}"
                    data-failed="{{ __('notifications.push.failed') }}"
                    data-denied="{{ __('notifications.push.denied') }}">
                    <p class="small text-secondary">{{ __('notifications.push.hint') }}</p>
                    <div class="alert alert-warning d-none" data-ow-push-message role="alert"></div>
                    <p class="small text-secondary d-none" data-ow-push-ios><x-icon name="device-mobile" class="me-1" />{{ __('notifications.push.ios') }}</p>
                    <p class="small text-warning d-none" data-ow-push-insecure>{{ __('notifications.push.insecure') }}</p>
                    <p class="small text-warning d-none" data-ow-push-unsupported>{{ __('notifications.push.unsupported') }}</p>
                    <button type="button" class="btn btn-primary d-none" data-ow-push-enable>
                        <x-icon name="bell-plus" class="me-1" />{{ __('notifications.push.enable') }}
                    </button>
                </div>
                @if ($devices->isEmpty())
                    <div class="card-body border-top text-secondary">{{ __('notifications.push.none') }}</div>
                @else
                    <div class="list-group list-group-flush border-top">
                        @foreach ($devices as $device)
                            <div class="list-group-item d-flex align-items-center gap-2" data-ow-push-device="{{ $device->endpoint_hash }}">
                                <x-icon :name="preg_match('/iPhone|iPad|Android/', $device->label) ? 'device-mobile' : 'device-desktop'" class="text-secondary" />
                                <div class="flex-fill">
                                    <div>
                                        {{ $device->label }}
                                        <x-badge tone="ok" class="ms-1 d-none" data-ow-push-current>{{ __('notifications.push.this_device') }}</x-badge>
                                    </div>
                                    <div class="small text-secondary">
                                        {{ __('notifications.push.added', ['date' => $device->created_at->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L')]) }}
                                        @if ($device->last_success_at)
                                            · {{ __('notifications.push.last_success', ['date' => $device->last_success_at->copy()->setTimezone(config('owpanel.timezone'))->isoFormat('L LT')]) }}
                                        @endif
                                        @if ($device->failures > 0)
                                            · <span class="text-warning">{{ trans_choice('notifications.push.failures', $device->failures, ['count' => $device->failures]) }}</span>
                                        @endif
                                    </div>
                                    {{-- The last deliveries with the answer of the push service (E-147). --}}
                                    @if ($device->deliveries->isNotEmpty())
                                        <details class="mt-1">
                                            <summary class="small">{{ __('notifications.push.deliveries.title', ['count' => $device->deliveries->count()]) }}</summary>
                                            <div class="table-responsive mt-2">
                                                <table class="table table-sm table-vcenter mb-0">
                                                    <tbody>
                                                        @foreach ($device->deliveries as $delivery)
                                                            <tr>
                                                                <td class="text-nowrap small"><x-time :value="$delivery->created_at" /></td>
                                                                <td class="small text-break">{{ $delivery->title }}</td>
                                                                <td class="text-end text-nowrap">
                                                                    <x-badge :tone="['accepted' => 'ok', 'rejected' => 'danger', 'unreachable' => 'warning'][$delivery->state()]">
                                                                        {{ __('notifications.push.deliveries.'.$delivery->state(), ['status' => $delivery->status]) }}
                                                                    </x-badge>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            <p class="small text-secondary mt-2 mb-0">{{ __('notifications.push.deliveries.hint') }}</p>
                                        </details>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('account.notifications.destroy', $device) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-ghost-danger">{{ __('notifications.push.remove') }}</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
                <x-slot:footer>
                    <form method="POST" action="{{ route('account.notifications.test') }}">
                        @csrf
                        <button type="submit" class="btn"><x-icon name="send" class="me-1" />{{ __('notifications.test.button') }}</button>
                    </form>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</x-layouts.app>

{{-- SSH settings of a server (E-155), read from the server after the page has loaded (ServerSsh). --}}
<div>
    {{-- SSH password login (E-155); info box top right. --}}
    @php
        $error = $ssh['error'] ?? null;
        $missing = $error ? [] : collect($ssh['accounts'])->where('key', false)->pluck('user')->all();
        $off = ! $error && ! $ssh['password_login'];
    @endphp
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <x-card :title="__('ssh.card')" :flush="true" :accent="$error ? 'red' : ($off ? 'green' : 'orange')">
                @unless ($error)
                    <x-slot:actions>
                        <x-badge :tone="$off ? 'ok' : 'warning'">{{ __($off ? 'ssh.state_off_badge' : 'ssh.state_on_badge') }}</x-badge>
                    </x-slot:actions>
                @endunless
                <div class="card-body">
                    @if ($error)
                        <p class="text-danger mb-0">{{ __('ssh.unavailable', ['error' => __('tasks.errors.'.$error) !== 'tasks.errors.'.$error ? __('tasks.errors.'.$error) : $error]) }}</p>
                    @else
                        <p class="mb-0">{{ __($off ? 'ssh.state_off' : 'ssh.state_on') }}</p>
                        @if ($off && ! $ssh['managed'])
                            <p class="text-secondary small mt-2 mb-0">{{ __('ssh.foreign') }}</p>
                        @endif
                        @if (! $off && $missing !== [])
                            <div class="alert alert-warning mt-3 mb-0" role="status">{{ __('ssh.missing') }}</div>
                        @endif
                    @endif
                    @if ($lastTask?->status === \App\Enums\TaskStatus::Failed)
                        <div class="alert alert-danger mt-3 mb-0" role="status">
                            {{ __('ssh.last_failed') }}
                            <a href="{{ route('tasks.show', $lastTask) }}" class="alert-link ms-1">{{ __('tasks.feedback.details') }}</a>
                        </div>
                    @endif
                </div>
                @unless ($error)
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('ssh.account') }}</th>
                                    <th scope="col">{{ __('ssh.key') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ssh['accounts'] as $account)
                                    <tr>
                                        <td><code>{{ $account['user'] }}</code></td>
                                        <td><x-badge :tone="$account['key'] ? 'ok' : 'warning'">{{ __($account['key'] ? 'ssh.key_yes' : 'ssh.key_no') }}</x-badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (! $off || $ssh['managed'])
                        <x-slot:footer>
                            <form method="POST" action="{{ route('servers.ssh.update', $server) }}" class="d-flex justify-content-end"
                                data-ow-busy="{{ __($off ? 'ssh.turning_on' : 'ssh.turning_off') }}" data-ow-task="server-ssh">
                                @csrf
                                <input type="hidden" name="password_login" value="{{ $off ? 'on' : 'off' }}">
                                <button type="submit" @class(['btn', 'btn-primary' => ! $off, 'btn-outline-secondary' => $off]) @disabled(! $off && $missing !== [])>
                                    <x-icon :name="$off ? 'lock-open' : 'lock'" class="me-1" />{{ __($off ? 'ssh.turn_on' : 'ssh.turn_off') }}
                                </button>
                            </form>
                        </x-slot:footer>
                    @endif
                @endunless
            </x-card>
        </div>
        <div class="col-lg-4">
            <x-card :title="__('ssh.title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('ssh.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
        @unless ($error)
            <div class="col-lg-8">
                <x-card :title="__('ssh.sftp_title')" :accent="$ssh['sftp_allowed'] ? 'green' : 'orange'">
                    <p @class(['mb-0', 'text-orange' => ! $ssh['sftp_allowed']])>{{ __($ssh['sftp_allowed'] ? 'ssh.sftp_ok' : 'ssh.sftp_blocked') }}</p>
                    @if ($ssh['allow_users'] !== '' || $ssh['allow_groups'] !== '')
                        <dl class="row small text-secondary mt-2 mb-0">
                            @if ($ssh['allow_users'] !== '')
                                <dt class="col-sm-4">AllowUsers</dt><dd class="col-sm-8"><code>{{ $ssh['allow_users'] }}</code></dd>
                            @endif
                            @if ($ssh['allow_groups'] !== '')
                                <dt class="col-sm-4">AllowGroups</dt><dd class="col-sm-8 mb-0"><code>{{ $ssh['allow_groups'] }}</code></dd>
                            @endif
                        </dl>
                    @endif
                </x-card>
            </div>
        @endunless
    </div>
</div>

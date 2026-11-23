{{-- fail2ban bans of a server (E-090), loaded after the page (FirewallBans). --}}
<div class="h-100">
    <x-card :title="__('firewall.bans.title')" :flush="true" class="h-100">
        <x-slot:actions>
            <span class="badge bg-secondary-lt">{{ trans_choice('firewall.bans.count', count($banned), ['count' => count($banned)]) }}</span>
        </x-slot:actions>
        @if ($bansError)
            <div class="card-body text-danger">{{ __('firewall.bans.error', ['error' => __('tasks.errors.'.$bansError) !== 'tasks.errors.'.$bansError ? __('tasks.errors.'.$bansError) : $bansError]) }}</div>
        @elseif ($banned === [])
            <div class="card-body text-secondary">{{ __('firewall.bans.empty') }}</div>
        @else
            <div class="table-responsive" style="max-height: 24rem">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('firewall.bans.address') }}</th>
                            <th scope="col">{{ __('firewall.bans.jail') }}</th>
                            <th scope="col">{{ __('firewall.bans.until') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('firewall.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($banned as $ban)
                            <tr>
                                <td>
                                    <code>{{ $ban['ip'] }}</code>
                                    @if ($ban['ip'] === $clientIp)
                                        <span class="badge bg-orange-lt ms-1">{{ __('firewall.bans.you') }}</span>
                                    @endif
                                </td>
                                <td>{{ \Illuminate\Support\Facades\Lang::has('firewall.bans.jails.'.$ban['jail']) ? __('firewall.bans.jails.'.$ban['jail']) : $ban['jail'] }}</td>
                                <td class="text-nowrap">{{ $ban['until'] ?? '–' }}</td>
                                <td class="text-end">
                                    <form data-ow-busy="{{ __('tasks.busy.unban') }}" data-ow-task="server-fail2ban" method="POST" action="{{ route('servers.firewall.unban', $server) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="ip" value="{{ $ban['ip'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('firewall.bans.unban') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <x-slot:footer>
            <div class="small text-secondary">{{ __('firewall.bans.hint') }}</div>
        </x-slot:footer>
    </x-card>
</div>

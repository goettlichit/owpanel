<?php

namespace App\Livewire;

use App\Jobs\ReadFail2ban;
use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Current fail2ban bans of a server (E-090). Reading them means a round
 * trip to the server; the firewall page shows at once and the bans follow.
 */
#[Defer]
class FirewallBans extends Component
{
    #[Locked]
    public string $serverId;

    #[Locked]
    public ?string $clientIp = null;

    public function mount(Server $server, ?string $clientIp = null): void
    {
        $this->serverId = (string) $server->getKey();
        $this->clientIp = $clientIp;
    }

    public function placeholder(): View
    {
        return view('livewire.card-loading', ['title' => __('firewall.bans.title')]);
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageFirewall', $server);
        $bans = ReadFail2ban::await($this->serverId);

        return view('livewire.firewall-bans', [
            'server' => $server,
            'clientIp' => $this->clientIp,
            'bansError' => $bans['error'] ?? null,
            'banned' => $bans['banned'] ?? [],
        ]);
    }
}

<?php

namespace App\Livewire;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Firewall\BlocklistIndex;
use App\Firewall\Blocklists;
use App\Jobs\ReadBlocklistHits;
use App\Models\Blocklist;
use App\Models\Server;
use App\Support\IpNetwork;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The blocklists of a server with their hits, own addresses on a list and
 * "Check address" (E-170). The hits come from the server and the lists are
 * matched against up to 250000 entries; the firewall page shows at once and
 * this part follows.
 */
#[Defer]
class BlocklistDetails extends Component
{
    #[Locked]
    public string $serverId;

    #[Locked]
    public ?string $clientIp = null;

    /** "Check address" from the query string of the page. */
    #[Locked]
    public ?string $check = null;

    public function mount(Server $server, ?string $clientIp = null, mixed $check = null): void
    {
        $this->serverId = (string) $server->getKey();
        $this->clientIp = $clientIp;
        $this->check = is_string($check) ? mb_substr(trim($check), 0, 60) : null;
    }

    public function placeholder(): View
    {
        return view('livewire.card-loading');
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageFirewall', $server);
        $blocklists = $server->blocklists()->orderBy('created_at')->orderBy('id')->get();
        $indexes = $blocklists->isEmpty() ? [] : Blocklists::indexes($blocklists);

        return view('livewire.blocklist-details', [
            'server' => $server,
            'blocklists' => $blocklists,
            'blocklistHits' => $this->hits($server, $blocklists, $indexes),
            'blocklistOwn' => $blocklists->isEmpty() ? [] : Blocklists::ownMatches($server, $blocklists, $indexes, $this->clientIp),
            'blocklistCheck' => $this->checkAddress($server, $blocklists, $indexes),
        ]);
    }

    /**
     * The hits of the blocklists (E-170). A server whose table is missing or
     * still has the layout without hit sets gets its lists loaded again, at
     * most once in ten minutes, so a reload of the page after the task
     * cannot start the next one.
     *
     * @param  Collection<int, Blocklist>  $blocklists
     * @param  array<string, BlocklistIndex>  $indexes
     * @return array<string, mixed>|null
     */
    private function hits(Server $server, Collection $blocklists, array $indexes): ?array
    {
        if ($blocklists->isEmpty()) {
            return null;
        }
        $hits = Blocklists::hits($blocklists, ReadBlocklistHits::await((string) $server->getKey()), $indexes);
        $hits['retried'] = false;
        if ($hits['error'] !== null || $hits['counting'] || (int) $blocklists->sum('entries') === 0) {
            return $hits;
        }
        $pending = $server->tasks()->where('script', ServerScript::ServerBlocklist->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists();
        if (! $pending && Cache::add('blocklist-reload:'.$server->getKey(), true, now()->addMinutes(10))) {
            Blocklists::apply($server, auth()->user());
            $pending = true;
        }
        // Loaded again recently and still not counting: say so instead of waiting.
        $hits['retried'] = ! $pending;

        return $hits;
    }

    /**
     * "Check address": on which lists an address is. Only reads; the address
     * is neither stored nor logged.
     *
     * @param  Collection<int, Blocklist>  $blocklists
     * @param  array<string, BlocklistIndex>  $indexes
     * @return array{input: string, address: ?string, lists: list<string>, excepted: bool}|null
     */
    private function checkAddress(Server $server, Collection $blocklists, array $indexes): ?array
    {
        $input = $this->check;
        if ($input === null || $input === '' || $blocklists->isEmpty()) {
            return null;
        }
        $address = IpNetwork::normalize($input);
        if ($address === null || str_contains($address, '/')) {
            return ['input' => $input, 'address' => null, 'lists' => [], 'excepted' => false];
        }

        return [
            'input' => $input,
            'address' => $address,
            'lists' => Blocklists::listsWith($blocklists, $indexes, $address),
            'excepted' => Blocklists::excepted($server, $address),
        ];
    }
}

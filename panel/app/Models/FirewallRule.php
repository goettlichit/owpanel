<?php

namespace App\Models;

use App\Support\IpNetwork;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An own firewall rule of a server (E-090): opens a port or a range for TCP,
 * UDP or both, for everyone or only for one address or network. The fixed
 * ports (SSH, HTTP, HTTPS, HTTP/3) are not stored here, they are always open.
 */
class FirewallRule extends Model
{
    use HasUlids;

    public const MAX_PER_SERVER = 50;

    public const PROTOCOLS = ['tcp', 'udp', 'both'];

    protected $fillable = ['protocol', 'ports', 'source', 'comment'];

    /**
     * "3306" or "8000-8010", each from 1 to 65535, the range in ascending
     * order. Returns the normalized form or null.
     */
    public static function normalizePorts(string $input): ?string
    {
        if (! preg_match('/^\s*(\d{1,5})\s*(?:[-:]\s*(\d{1,5}))?\s*$/', $input, $match)) {
            return null;
        }
        $from = (int) $match[1];
        $to = isset($match[2]) ? (int) $match[2] : $from;
        if ($from < 1 || $to > 65535 || $from > $to) {
            return null;
        }

        return $from === $to ? (string) $from : $from.'-'.$to;
    }

    /**
     * Lines for firewall-rules: protocol, ports and source, separated by
     * tabs; "both" becomes one line for TCP and one for UDP.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $source = $this->source === null ? 'any' : IpNetwork::withPrefix($this->source);
        $protocols = $this->protocol === 'both' ? ['tcp', 'udp'] : [$this->protocol];

        return array_map(fn (string $protocol): string => $protocol."\t".$this->ports."\t".$source, $protocols);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}

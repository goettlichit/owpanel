<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address or network that fail2ban never bans on a server (E-090), such
 * as the office or the administrator's connection.
 */
class Fail2banIgnore extends Model
{
    use HasUlids;

    public const MAX_PER_SERVER = 50;

    protected $fillable = ['address', 'comment'];

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}

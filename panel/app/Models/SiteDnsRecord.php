<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DNS record the panel set for a website (E-116).
 */
class SiteDnsRecord extends Model
{
    use HasUlids;

    protected $fillable = ['zone_id', 'zone_name', 'name', 'type', 'value', 'ttl'];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}

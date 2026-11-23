<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One push message handed to the push service of a device, with its answer
 * (E-147). The newest KEEP per device stay.
 */
class PushDelivery extends Model
{
    public const UPDATED_AT = null;

    public const KEEP = 20;

    protected $fillable = ['status', 'title'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PushSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class, 'push_subscription_id');
    }

    /**
     * "accepted", "rejected" or "unreachable", for the list.
     */
    public function state(): string
    {
        return match (true) {
            $this->status === null => 'unreachable',
            $this->status >= 200 && $this->status < 300 => 'accepted',
            default => 'rejected',
        };
    }
}

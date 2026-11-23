<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message held back during the quiet hours of an administrator (E-101).
 * When the quiet hours end, all held messages of a channel go out together.
 */
class PendingNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'channel', 'message'];

    protected function casts(): array
    {
        return [
            'message' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

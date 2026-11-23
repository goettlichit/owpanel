<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A panel account linked with an account at Pocket ID or another OpenID
 * Connect provider (E-113, E-114). Found by issuer and subject only.
 */
class OidcIdentity extends Model
{
    use HasUlids;

    protected $fillable = ['issuer', 'subject', 'email'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
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

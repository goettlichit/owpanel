<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit log (E-053).
 *
 * Context values are stored as given, so callers pass only what is needed
 * to understand an action: never passwords, tokens, codes or e-mail
 * addresses of people who are not logged in.
 */
class Audit
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function record(string $action, ?Model $subject = null, array $context = [], ?User $user = null): AuditLog
    {
        $user ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'customer_id' => $user?->customer_id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'context' => $context ?: null,
        ]);
    }
}

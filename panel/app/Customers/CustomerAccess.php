<?php

namespace App\Customers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerInvitation;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The access of a customer to the customer area (E-128): one account with
 * the role "customer", created by an administrator. The customer sets his
 * password through an invitation link (72 hours, once) or gets a start
 * password shown once that he must change. Locking refuses every login and
 * ends open sessions (E-127).
 */
class CustomerAccess
{
    public const INVITATION_HOURS = 72;

    /**
     * Creates the account. Returns the start password when one was chosen,
     * otherwise sends the invitation and returns null.
     */
    public static function create(Customer $customer, string $email, bool $invite, User $actor): ?string
    {
        $user = new User;
        $password = Str::password(20, symbols: false);
        $user->forceFill([
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => Str::lower($email),
            'locale' => $customer->locale,
            'role' => Role::Customer,
            'customer_id' => $customer->id,
            // With an invitation nobody knows this password; it is replaced
            // when the customer follows the link.
            'password' => $password,
            'password_change_required' => ! $invite,
        ])->save();

        Audit::record('customer.access_created', $customer, ['email' => $user->email, 'invite' => $invite], $actor);

        if ($invite) {
            self::invite($user, $actor);

            return null;
        }

        return $password;
    }

    /**
     * Sends a new invitation link; an older one stops working.
     */
    public static function invite(User $user, User $actor): void
    {
        $token = Str::random(64);
        $user->forceFill([
            'invitation_token' => hash('sha256', $token),
            'invitation_expires_at' => now()->addHours(self::INVITATION_HOURS),
        ])->save();

        $user->notify((new CustomerInvitation($token))->locale($user->locale ?? config('app.locale')));
        Audit::record('customer.invitation_sent', $user->customer, ['email' => $user->email], $actor);
    }

    /**
     * Finds the account an invitation link belongs to, or null when the
     * link is wrong, used or expired.
     */
    public static function invited(string $userId, string $token): ?User
    {
        $user = User::whereKey($userId)->where('role', Role::Customer)->first();
        if ($user === null || ! $user->hasOpenInvitation()) {
            return null;
        }

        return hash_equals((string) $user->invitation_token, hash('sha256', $token)) ? $user : null;
    }

    public static function accept(User $user, string $password): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'password_change_required' => false,
            'invitation_token' => null,
            'invitation_expires_at' => null,
        ])->save();

        Audit::record('customer.invitation_accepted', $user->customer, ['email' => $user->email], $user);
    }

    /**
     * Replaces the password with a new start password the customer must
     * change at his next login (E-140). An open invitation stops working and
     * open sessions end, so only the new password counts. Returns the
     * password to show once.
     */
    public static function resetPassword(User $user, User $actor): string
    {
        $password = Str::password(20, symbols: false);
        $user->forceFill([
            'password' => $password,
            'password_change_required' => true,
            'invitation_token' => null,
            'invitation_expires_at' => null,
        ])->save();

        self::endSessions($user);
        Audit::record('customer.password_reset', $user->customer, ['email' => $user->email], $actor);

        return $password;
    }

    public static function lock(User $user, User $actor): void
    {
        $user->forceFill(['locked_at' => now()])->save();
        self::endSessions($user);
        Audit::record('customer.access_locked', $user->customer, ['email' => $user->email], $actor);
    }

    public static function unlock(User $user, User $actor): void
    {
        $user->forceFill(['locked_at' => null])->save();
        Audit::record('customer.access_unlocked', $user->customer, ['email' => $user->email], $actor);
    }

    public static function delete(User $user, User $actor): void
    {
        $customer = $user->customer;
        Audit::record('customer.access_deleted', $customer, ['email' => $user->email], $actor);
        self::endSessions($user);
        $user->oidcIdentities()->delete();
        $user->delete();
    }

    /**
     * Sessions are kept in the database (SESSION_DRIVER=database); a session
     * elsewhere ends on its next request (EnsureAccountNotLocked).
     */
    private static function endSessions(User $user): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
    }
}

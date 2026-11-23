<?php

namespace App\Customers;

use App\Models\Customer;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Oidc\OidcSettings;
use App\Oidc\PocketIdApi;
use App\Support\Audit;
use RuntimeException;

/**
 * Creating and linking a customer in Pocket ID (E-138, E-141). Used by the
 * card on the page "Access" and when an access is created with the option
 * "also in Pocket ID".
 */
class CustomerPocketId
{
    public const LINK_HOURS = 72;

    public static function api(): PocketIdApi
    {
        return PocketIdApi::fromSettings() ?? throw new RuntimeException(__('customers.pocket_id.no_api'));
    }

    /**
     * Creates the customer in Pocket ID and links him. Throws when Pocket ID
     * refuses or a user with his address exists already; that one is linked
     * only after an administrator confirmed it (E-138).
     */
    public static function createAndLink(Customer $customer, User $user, User $actor): void
    {
        $api = self::api();
        if ($api->findByEmail($user->email) !== null) {
            throw new RuntimeException(__('customers.pocket_id.exists'));
        }
        $remote = $api->create(PocketIdApi::username($user->email, $customer->display_number), $user->email,
            $customer->first_name, $customer->last_name, $user->locale ?? 'de');

        self::attach($customer, $user, $remote, 'customer.pocket_id_created', $actor);
    }

    /**
     * Links a user of Pocket ID with the customer's account and allows
     * Pocket ID for it. Throws when the user belongs to another account.
     *
     * @param  array{id: string, username: string, email: ?string}  $remote
     */
    public static function attach(Customer $customer, User $user, array $remote, string $action, User $actor): void
    {
        $issuer = (string) OidcSettings::issuer();
        $taken = OidcIdentity::where('issuer', $issuer)->where('subject', $remote['id'])->where('user_id', '!=', $user->id)->exists();
        if ($taken) {
            throw new RuntimeException(__('customers.pocket_id.taken', ['account' => $remote['email'] ?? $remote['username'] ?? $remote['id']]));
        }

        $user->oidcIdentities()->delete();
        $user->oidcIdentities()->create(['issuer' => $issuer, 'subject' => $remote['id'], 'email' => $remote['email']]);
        $user->forceFill(['oidc_allowed' => true])->save();
        Audit::record($action, $customer, ['username' => $remote['username']], $actor);
    }

    /**
     * Lets Pocket ID send the login link; needs mail set up in Pocket ID.
     */
    public static function sendLink(Customer $customer, OidcIdentity $identity, User $actor): void
    {
        self::api()->sendLoginEmail($identity->subject, self::LINK_HOURS);
        Audit::record('customer.pocket_id_link_sent', $customer, [], $actor);
    }
}

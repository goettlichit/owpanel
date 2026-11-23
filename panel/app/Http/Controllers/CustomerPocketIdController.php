<?php

namespace App\Http\Controllers;

use App\Customers\CustomerPocketId;
use App\Models\Customer;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Oidc\OidcSettings;
use App\Oidc\PocketIdApi;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Pocket ID on the page "Access" of a customer (E-130, E-138): allow it,
 * find or create the customer in Pocket ID and link him, get a login link
 * for the passkey, unlink. Linking without the customer's own login is the
 * exception of E-130: automatic only for a user the panel created itself,
 * otherwise after the administrator confirmed the user found by address.
 */
class CustomerPocketIdController extends Controller
{
    public const LINK_HOURS = CustomerPocketId::LINK_HOURS;

    public function allow(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        $allowed = $request->boolean('allowed');
        $user->forceFill(['oidc_allowed' => $allowed])->save();
        if (! $allowed) {
            $user->oidcIdentities()->delete();
        }
        Audit::record($allowed ? 'user.oidc_allowed' : 'user.oidc_revoked', $user, ['email' => $user->email], $request->user());

        return $this->back($customer)->with('status', __($allowed ? 'customers.pocket_id.toast.allowed' : 'customers.pocket_id.toast.revoked', ['name' => OidcSettings::name()]));
    }

    /**
     * Looks the customer up in Pocket ID by his address; the result is
     * shown once on the page.
     */
    public function search(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);

        try {
            $found = $this->api()->findByEmail($user->email);
        } catch (RuntimeException $e) {
            return $this->back($customer)->with('error', $e->getMessage());
        }

        return $this->back($customer)->with('pocket_id.search', ['found' => $found]);
    }

    /**
     * Links the user the administrator confirmed; its address must still
     * be the customer's.
     */
    public function link(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        $id = (string) $request->validate(['pocket_id_user' => ['required', 'string', 'max:100']])['pocket_id_user'];

        try {
            $remote = $this->api()->get($id);
        } catch (RuntimeException $e) {
            return $this->back($customer)->with('error', $e->getMessage());
        }
        if (mb_strtolower((string) $remote['email']) !== mb_strtolower($user->email)) {
            return $this->back($customer)->with('error', __('customers.pocket_id.mismatch'));
        }

        try {
            CustomerPocketId::attach($customer, $user, $remote, 'customer.pocket_id_linked', $request->user());
        } catch (RuntimeException $e) {
            return $this->back($customer)->with('error', $e->getMessage());
        }

        return $this->back($customer)->with('status', __('customers.pocket_id.toast.linked', ['name' => OidcSettings::name()]));
    }

    /**
     * Creates the customer in Pocket ID and links him at once.
     */
    public function create(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);

        try {
            CustomerPocketId::createAndLink($customer, $user, $request->user());
        } catch (RuntimeException $e) {
            return $this->back($customer)->with('error', $e->getMessage());
        }

        return $this->back($customer)->with('status', __('customers.pocket_id.toast.linked', ['name' => OidcSettings::name()]));
    }

    /**
     * A login link to set up the passkey, shown once.
     */
    public function loginLink(Request $request, Customer $customer): RedirectResponse
    {
        $identity = $this->identity($customer);

        try {
            $link = $this->api()->loginLink($identity->subject, self::LINK_HOURS);
        } catch (RuntimeException $e) {
            return $this->back($customer)->with('error', $e->getMessage());
        }
        Audit::record('customer.pocket_id_link_created', $customer, [], $request->user());

        return $this->back($customer)->with('pocket_id.link', $link);
    }

    /**
     * Lets Pocket ID send the login link; needs mail set up in Pocket ID.
     */
    public function sendLink(Request $request, Customer $customer): RedirectResponse
    {
        $identity = $this->identity($customer);

        try {
            CustomerPocketId::sendLink($customer, $identity, $request->user());
        } catch (RuntimeException $e) {
            return $this->back($customer)->with('error', $e->getMessage());
        }

        return $this->back($customer)->with('status', __('customers.pocket_id.toast.sent', ['name' => OidcSettings::name()]));
    }

    /**
     * Removes the link in the panel; the user in Pocket ID stays.
     */
    public function unlink(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        $user->oidcIdentities()->delete();
        Audit::record('customer.pocket_id_unlinked', $customer, [], $request->user());

        return $this->back($customer)->with('status', __('customers.pocket_id.toast.unlinked', ['name' => OidcSettings::name()]));
    }

    private function account(Customer $customer): User
    {
        Gate::authorize('manageAccess', $customer);
        abort_unless(OidcSettings::configured(), 404);

        return $customer->users()->firstOrFail();
    }

    private function identity(Customer $customer): OidcIdentity
    {
        $identity = $this->account($customer)->oidcIdentities()->where('issuer', OidcSettings::issuer())->first();
        abort_if($identity === null, 404);

        return $identity;
    }

    private function api(): PocketIdApi
    {
        return CustomerPocketId::api();
    }

    private function back(Customer $customer): RedirectResponse
    {
        return redirect()->route('customers.access', $customer);
    }
}

<?php

namespace App\Http\Controllers;

use App\Customers\CustomerAccess;
use App\Customers\CustomerPocketId;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Oidc\OidcSettings;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The card "Access to the customer area" on the customer page (E-128).
 * Administrators only; no second password prompt (E-135).
 */
class CustomerAccessController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('createAccess', $customer);

        $request->merge(['access_email' => Str::lower(trim((string) $request->input('access_email')))]);
        $validated = $request->validate([
            'access_email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'method' => ['required', Rule::in(MailSettings::configured() ? ['invite', 'password'] : ['password'])],
            'pocket_id' => ['nullable', 'boolean'],
        ]);

        $password = CustomerAccess::create($customer, $validated['access_email'], $validated['method'] === 'invite', $request->user());

        $redirect = redirect()->route('customers.access', $customer);

        // Optionally also in Pocket ID (E-141). The access stays when this
        // fails; the card "Pocket ID" then offers the single steps.
        if (($validated['pocket_id'] ?? false) && self::pocketIdAvailable()) {
            $user = $customer->users()->sole();
            try {
                CustomerPocketId::createAndLink($customer, $user, $request->user());
                CustomerPocketId::sendLink($customer, $user->oidcIdentities()->sole(), $request->user());
                $redirect->with('pocket_id.provisioned', __('customers.pocket_id.provisioned', ['name' => OidcSettings::name()]));
            } catch (RuntimeException $e) {
                $redirect->with('pocket_id.provisioned', __('customers.pocket_id.provision_failed', ['name' => OidcSettings::name(), 'error' => $e->getMessage()]))
                    ->with('pocket_id.provision_error', true);
            }
        }

        // Shown once on the customer page; the session is encrypted.
        return $password === null
            ? $redirect->with('status', __('customers.access.toast.invited'))
            : $redirect->with('customer.access_password', $password);
    }

    public function resend(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        Gate::authorize('manageAccess', $customer);
        abort_unless(MailSettings::configured(), 404);

        CustomerAccess::invite($user, $request->user());

        return redirect()->route('customers.access', $customer)->with('status', __('customers.access.toast.invited'));
    }

    public function resetPassword(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        Gate::authorize('manageAccess', $customer);

        // Shown once on the customer page like a new start password.
        return redirect()->route('customers.access', $customer)
            ->with('customer.access_password', CustomerAccess::resetPassword($user, $request->user()));
    }

    public function lock(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        Gate::authorize('manageAccess', $customer);

        if (! $user->isLocked()) {
            CustomerAccess::lock($user, $request->user());
        }

        return redirect()->route('customers.access', $customer)->with('status', __('customers.access.toast.locked'));
    }

    public function unlock(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        Gate::authorize('manageAccess', $customer);

        if ($user->isLocked()) {
            CustomerAccess::unlock($user, $request->user());
        }

        return redirect()->route('customers.access', $customer)->with('status', __('customers.access.toast.unlocked'));
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $user = $this->account($customer);
        Gate::authorize('manageAccess', $customer);

        // A wrong confirmation is a reaction to the action: a toast (E-083).
        if ($request->input('confirm_email') !== $user->email) {
            return redirect()->route('customers.access', $customer)->with('error', __('customers.access.delete.mismatch'));
        }

        CustomerAccess::delete($user, $request->user());

        return redirect()->route('customers.access', $customer)->with('status', __('customers.access.toast.deleted'));
    }

    public static function pocketIdAvailable(): bool
    {
        return OidcSettings::configured() && Setting::get(Setting::POCKET_ID_API_KEY) !== null;
    }

    private function account(Customer $customer): User
    {
        $user = $customer->users()->first();
        abort_if($user === null, 404);

        return $user;
    }
}

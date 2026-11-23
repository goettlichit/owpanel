<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Customers\CustomerAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The invitation link of a customer (E-128): sets the first password, then
 * the customer logs in as usual. The link works 72 hours and once; a wrong,
 * used or expired link shows the same page without a form.
 */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $user, string $token): View
    {
        $account = CustomerAccess::invited($user, $token);
        if ($account !== null) {
            app()->setLocale($account->locale ?? app()->getLocale());
        }

        return view('invitation.show', ['account' => $account, 'user' => $user, 'token' => $token]);
    }

    public function store(Request $request, string $user, string $token): RedirectResponse
    {
        $account = CustomerAccess::invited($user, $token);
        if ($account === null) {
            return redirect()->route('invitation.show', ['user' => $user, 'token' => $token]);
        }

        $validated = $request->validate(['password' => $this->passwordRules()]);
        CustomerAccess::accept($account, $validated['password']);

        return redirect()->route('login')->with('status', __('auth.invitation.done'));
    }
}

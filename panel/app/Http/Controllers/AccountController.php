<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Own account: preferences, password and two-factor authentication. The
 * forms post to Fortify's routes.
 */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.show', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Sign-in and security: two-factor authentication (set up, confirm,
     * recovery codes) and the link with Pocket ID (E-114). The password is
     * changed on the profile.
     */
    public function security(Request $request): View
    {
        return view('account.security', [
            'user' => $request->user(),
        ]);
    }
}

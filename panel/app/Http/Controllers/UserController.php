<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Oidc\OidcSettings;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Panel accounts (E-081): list, add an administrator, delete.
 *
 * A new account gets a random start password, shown once to the creating
 * administrator. It must be changed at the first sign-in, and the new
 * administrator must set up two-factor authentication before using the
 * panel. Customer accounts come with the customer area; for them an
 * administrator turns the login with Pocket ID on or off (E-114).
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        return view('users.index', [
            'users' => User::with('oidcIdentities')->orderBy('role')->orderBy('last_name')->orderBy('first_name')->get(),
            'current' => $request->user(),
            'created' => $request->session()->get('user.created'),
            'oidcName' => OidcSettings::configured() ? OidcSettings::name() : null,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        $password = Str::password(20, symbols: false);

        $user = new User;
        $user->forceFill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => Str::lower($validated['email']),
            'role' => Role::Admin,
            'password' => $password,
            'password_change_required' => true,
        ])->save();

        Audit::record('user.created', $user, ['email' => $user->email, 'role' => $user->role->value], $request->user());

        // Shown once on the next page; the session is encrypted.
        return redirect()->route('users.index')
            ->with('user.created', ['name' => $user->name, 'email' => $user->email, 'password' => $password]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        // A wrong confirmation is a reaction to the action: a toast (E-083).
        if ($request->input('confirm_email') !== $user->email) {
            return redirect()->route('users.index')->with('error', __('users.delete.mismatch'));
        }

        Audit::record('user.deleted', $user, ['email' => $user->email, 'role' => $user->role->value], $request->user());
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', __('users.delete.done', ['email' => $user->email]));
    }

    /**
     * Turns the login with Pocket ID on or off for a customer account.
     * Turning it off removes an existing link as well (E-114).
     */
    public function updateOidc(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageOidc', $user);

        $allowed = $request->boolean('allowed');
        $user->forceFill(['oidc_allowed' => $allowed])->save();
        if (! $allowed) {
            $user->oidcIdentities()->delete();
        }
        Audit::record($allowed ? 'user.oidc_allowed' : 'user.oidc_revoked', $user, ['email' => $user->email], $request->user());

        return redirect()->route('users.index')->with('success', __($allowed ? 'users.oidc.allowed' : 'users.oidc.revoked', [
            'email' => $user->email,
            'name' => OidcSettings::name(),
        ]));
    }
}

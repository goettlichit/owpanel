<?php

namespace App\Listeners;

use App\Models\User;
use App\Security\LoginWatch;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Writes sign-ins and changes to the login security into the audit log
 * (docs/SICHERHEIT.md, "Änderungen und Nachvollziehbarkeit", rule 1).
 *
 * Failed attempts store the user only if the account exists, never the
 * typed e-mail address or password.
 */
class RecordAuthenticationEvents
{
    public function login(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            // Set by the Pocket ID login just before it signs in (E-114).
            $viaOidc = app()->bound('session') && session()->get('auth.via') === 'oidc';
            Audit::record($viaOidc ? 'auth.oidc_login' : 'auth.login', user: $event->user);
            // New devices of administrators (E-160). Never blocks a sign-in.
            if (app()->bound('request')) {
                rescue(fn () => LoginWatch::signedIn($event->user, request()));
            }
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.logout', user: $event->user);
        }
    }

    public function failed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        Audit::record('auth.failed', $user, user: $user);
    }

    public function lockout(Lockout $event): void
    {
        Audit::record('auth.lockout');
        rescue(fn () => LoginWatch::lockedOut($event->request));
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        Audit::record('auth.two_factor_failed', $event->user, user: $event->user);
    }

    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        Audit::record('account.two_factor_enabled', $event->user, user: $event->user);
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::record('account.two_factor_confirmed', $event->user, user: $event->user);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::record('account.two_factor_disabled', $event->user, user: $event->user);
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        Audit::record('account.recovery_codes_generated', $event->user, user: $event->user);
    }

    public function recoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        Audit::record('auth.recovery_code_used', $event->user, user: $event->user);
    }

    public function passwordUpdated(PasswordUpdatedViaController $event): void
    {
        Audit::record('account.password_updated', $event->user, user: $event->user);
    }

    public function passwordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('auth.password_reset', $event->user, user: $event->user);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
            TwoFactorAuthenticationEnabled::class => 'twoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            RecoveryCodesGenerated::class => 'recoveryCodesGenerated',
            RecoveryCodeReplaced::class => 'recoveryCodeUsed',
            PasswordUpdatedViaController::class => 'passwordUpdated',
            PasswordReset::class => 'passwordReset',
        ];
    }
}

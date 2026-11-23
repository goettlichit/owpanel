<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Connects Fortify to the panel: own Tabler views, password change and the
 * limits for failed attempts (docs/SICHERHEIT.md, "Anmeldung am Panel").
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::loginView(fn () => view('auth.login'));
        // The usual check of address and password; a locked account (E-127)
        // is refused only after a correct password, so the message tells
        // nothing to someone who guesses addresses.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower((string) $request->input(Fortify::username())))->first();
            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }
            if ($user->isLocked()) {
                Audit::record('auth.failed', $user, ['reason' => 'locked'], null);
                // A red message above the form, as after Pocket ID and for
                // ended sessions; under the address field it would read like
                // wrong credentials.
                session()->flash('error', __('auth.locked'));

                throw ValidationException::withMessages(['locked' => __('auth.locked')]);
            }

            return $user;
        });
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        // Password reset by mail (E-094).
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);

        // Five attempts per minute for each combination of account and
        // address, and twenty per minute for each address, so one address
        // cannot try many accounts.
        // With named limiters Fortify throttles by route and never fires
        // its Lockout event; the limits fire it themselves, for the audit
        // log and the notification (E-160). The answer stays the 429 page.
        $lockout = function (Request $request, array $headers): never {
            event(new Lockout($request));

            throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
        };

        RateLimiter::for('login', function (Request $request) use ($lockout) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip())->response($lockout),
                Limit::perMinute(20)->by('ip|'.$request->ip())->response($lockout),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) use ($lockout) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'))->response($lockout);
        });

        // All other sending Fortify forms (E-156): password, confirmation
        // and two-factor changes. Login, the second factor and reset links
        // have their own limits (above and PasswordResetAvailable).
        RateLimiter::for('fortify', function (Request $request) {
            if ($request->isMethodSafe() || $request->routeIs('login.store', 'two-factor.login.store', 'password.email', 'password.update')) {
                return Limit::none();
            }

            return Limit::perMinute(10)->by('fortify|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}

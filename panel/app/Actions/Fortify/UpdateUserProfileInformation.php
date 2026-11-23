<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Users change their own first and last name. The e-mail address is the
 * user name; only an administrator changes it (milestone 3, user
 * management), because the panel cannot yet confirm a new address by mail.
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
        ])->save();

        Audit::record('account.profile_updated', $user, user: $user);
    }
}

<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * New password from the reset link (E-094). Two-factor authentication
 * stays as it is; the next login asks for the code as usual.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => $this->passwordRules()])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_change_required' => false,
        ])->save();
    }
}

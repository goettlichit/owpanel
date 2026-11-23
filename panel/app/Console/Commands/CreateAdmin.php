<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an administrator. Used for the first account; the setup wizard
 * in milestone 3 will call it as well. Passwords are never accepted as a
 * command-line argument, because arguments show up in the process list
 * and shell history: they are typed or read from standard input.
 *
 *   php artisan owpanel:create-admin
 *   printf '%s\n' "$PASSWORD" | php artisan owpanel:create-admin --first-name=… --last-name=… --email=… --password-stdin
 *
 * --skip-existing lets the installer run again after an interruption: an
 * administrator with the same e-mail is kept as he is, the password is not
 * changed.
 */
class CreateAdmin extends Command
{
    protected $signature = 'owpanel:create-admin
        {--first-name= : First name}
        {--last-name= : Last name}
        {--email= : E-mail address used to sign in}
        {--password-stdin : Read the password from standard input}
        {--skip-existing : Succeed without changes if an administrator with this e-mail exists}';

    protected $description = 'Create a panel administrator';

    public function handle(): int
    {
        $firstName = $this->option('first-name') ?? $this->ask('First name');
        $lastName = $this->option('last-name') ?? $this->ask('Last name');
        $email = $this->option('email') ?? $this->ask('E-mail');

        if ($this->option('skip-existing') && is_string($email)) {
            $existing = User::where('email', strtolower(trim($email)))->first();
            if ($existing !== null) {
                if ($existing->role !== Role::Admin) {
                    $this->error('A user with this e-mail exists and is not an administrator.');

                    return self::FAILURE;
                }
                $this->info("Administrator {$existing->email} exists already, nothing changed.");

                return self::SUCCESS;
            }
        }

        if ($this->option('password-stdin')) {
            $password = rtrim((string) fgets(STDIN), "\r\n");
            $confirmation = $password;
        } else {
            $password = (string) $this->secret('Password (at least 12 characters)');
            $confirmation = (string) $this->secret('Repeat password');
        }

        $validator = Validator::make([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $user = new User([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->role = Role::Admin;
        $user->save();

        Audit::record('user.created', $user, ['role' => Role::Admin->value, 'source' => 'console']);

        $this->info("Administrator {$user->email} created. Two-factor authentication is set up at the first sign-in.");

        return self::SUCCESS;
    }
}

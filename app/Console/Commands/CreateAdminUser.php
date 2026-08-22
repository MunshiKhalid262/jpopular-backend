<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Provisions a production administrator interactively.
 *
 * This is the ONLY sanctioned way to create the first production admin. No
 * credential is committed, seeded, logged, or echoed:
 *   - the password is read with secret() so it is never rendered or shell-logged;
 *   - it is validated against the same policy the API enforces;
 *   - it is never written to output, and the command refuses --no-interaction
 *     so it cannot be driven from a shell history or CI script argument.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Interactively create or promote an administrator (password never displayed)';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error(
                'app:create-admin must run interactively so the password is never passed as an argument.'
            );

            return self::FAILURE;
        }

        if (Role::query()->where('name', RoleName::Admin->value)->doesntExist()) {
            $this->components->error(
                'The admin role does not exist yet. Run `php artisan db:seed --force` first.'
            );

            return self::FAILURE;
        }

        $name = (string) $this->ask('Full name');
        $email = (string) $this->ask('Email address');
        $password = (string) $this->secret('Password (input hidden)');
        $confirmation = (string) $this->secret('Confirm password');

        $existing = User::withTrashed()->where('email', $email)->first();

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => [
                    'required', 'string', 'email', 'max:160',
                    // An existing account is promoted rather than rejected.
                    Rule::unique('users', 'email')->ignore($existing?->getKey()),
                ],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            $this->newLine();
            $this->components->error('Could not create the administrator:');

            foreach ($validator->errors()->all() as $message) {
                $this->line("  • {$message}");
            }

            return self::FAILURE;
        }

        $promoting = $existing !== null;

        if ($promoting && ! $this->confirm("{$email} already exists. Reset its password and grant the Admin role?", false)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($existing, $name, $email, $password): void {
            $user = $existing ?? new User;

            if ($user->trashed()) {
                $user->restore();
            }

            $user->name = $name;
            $user->email = $email;
            $user->password = $password; // hashed by the model cast
            $user->is_active = true;
            $user->email_verified_at ??= now();
            $user->save();

            $user->assignRole(RoleName::Admin->value);

            // Any session issued under the previous password is invalidated.
            $user->tokens()->delete();
        });

        $this->newLine();
        $this->components->info(sprintf(
            'Administrator %s: %s',
            $promoting ? 'updated' : 'created',
            $email,
        ));
        $this->line('  The password was not displayed and has not been logged.');

        return self::SUCCESS;
    }
}

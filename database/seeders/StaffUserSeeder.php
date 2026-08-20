<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Provisions the configured staff accounts (Admin + Manager) in any
 * environment, from environment variables only.
 *
 * Safe to run on every deploy:
 *   - an account that does not exist is created;
 *   - an account that exists is restored/reactivated and has its role re-synced,
 *     but its password is NEVER overwritten -- so re-running cannot silently
 *     reset a password an operator has since changed;
 *   - a blank SEED_*_PASSWORD generates a strong random password and prints it
 *     once, so a forgotten variable can never produce a predictable credential.
 *
 * Passwords are validated against the same policy the API enforces
 * (Password::defaults()), so a weak value fails loudly here rather than
 * creating an account that cannot later change its own password.
 */
class StaffUserSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{key: string, role: string, name: string, email: string, password: string|null}> $staff */
        $staff = config('seeding.staff', []);

        foreach ($staff as $account) {
            $this->provision($account);
        }
    }

    /**
     * @param  array{key: string, role: string, name: string, email: string, password: string|null}  $account
     */
    private function provision(array $account): void
    {
        $email = $account['email'];

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->name = $account['name'];
            $existing->is_active = true;
            $existing->save();
            $existing->syncRoles([$account['role']]);

            $this->command?->warn("• {$email} already exists — role re-synced, password left unchanged.");

            return;
        }

        [$password, $wasGenerated] = $this->resolvePassword($account);

        if ($password === null) {
            return; // validation failed; message already emitted
        }

        $user = new User;
        $user->name = $account['name'];
        $user->email = $email;
        $user->password = $password; // hashed by the model cast
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $user->syncRoles([$account['role']]);

        $this->command?->info("• created {$email} ({$account['role']})");

        if ($wasGenerated) {
            $envKey = 'SEED_'.mb_strtoupper($account['key']).'_PASSWORD';
            $this->command?->warn("  {$envKey} was blank — generated password, shown once:");
            $this->command?->line("      {$password}");
        }
    }

    /**
     * @param  array{key: string, role: string, name: string, email: string, password: string|null}  $account
     * @return array{0: string|null, 1: bool}
     */
    private function resolvePassword(array $account): array
    {
        $configured = $account['password'];

        if (! is_string($configured) || $configured === '') {
            // 24 alphanumeric characters: satisfies the policy and is safe to
            // paste into a .env file without quoting concerns.
            return [Str::password(24, symbols: false), true];
        }

        $validator = Validator::make(
            ['password' => $configured],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        if ($validator->fails()) {
            $envKey = 'SEED_'.mb_strtoupper($account['key']).'_PASSWORD';

            $this->command?->error("✗ {$account['email']} skipped — {$envKey} fails the password policy:");

            foreach ($validator->errors()->get('password') as $message) {
                $this->command?->line("      {$message}");
            }

            return [null, false];
        }

        return [$configured, false];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstrap (or revoke) admin access from the CLI, since the Filament panel is now
 * gated on is_admin and the first admin therefore cannot be created from inside it.
 * Once one admin exists they can toggle others from the panel's Users resource.
 *
 * No silent failure: an unknown email or an already-correct state is reported back
 * with a clear reason and a non-zero / zero exit code as appropriate.
 */
class MakeUserAdmin extends Command
{
    protected $signature = 'user:make-admin {email : The email of the user to promote}
                            {--revoke : Remove admin access instead of granting it}';

    protected $description = 'Grant (or, with --revoke, remove) Filament admin-panel access for a user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $revoke = (bool) $this->option('revoke');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $target = ! $revoke;

        if ($user->is_admin === $target) {
            $this->warn("User {$email} is already ".($target ? 'an admin' : 'a non-admin').'; nothing to do.');

            return self::SUCCESS;
        }

        $user->is_admin = $target;
        $user->save();

        $this->info(($target ? 'Granted' : 'Revoked')." admin access for {$email}.");

        return self::SUCCESS;
    }
}

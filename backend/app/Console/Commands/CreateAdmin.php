<?php

namespace App\Console\Commands;

use App\Support\AuthSessions;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan store:create-admin owner@example.com "Full Name" [owner|manager|staff|order_manager|order_assistant]
 * The password comes from ADMIN_PASSWORD (for scripts) or a hidden prompt – never from the arguments.
 * An existing email is updated: new password, name and role, and reactivated.
 */
class CreateAdmin extends Command
{
    protected $signature = 'store:create-admin {email} {name} {role=owner}';

    protected $description = 'Create (or reset) an admin account';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = trim($this->argument('name'));
        $role = $this->argument('role');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }
        if (! in_array($role, Roles::ALL, true)) {
            $this->error('Role must be one of: '.implode(', ', Roles::ALL));

            return self::FAILURE;
        }
        $password = (string) (getenv('ADMIN_PASSWORD') ?: '');
        if ($password === '') {
            $password = (string) $this->secret('Password (at least 10 characters)');
        }
        if (mb_strlen($password) < 10) {
            $this->error('The password must have at least 10 characters.');

            return self::FAILURE;
        }
        $existing = DB::table('admins')->where('email', $email)->first();
        $row = ['name' => $name ?: $email, 'role' => $role, 'active' => 1, 'password_hash' => Hash::make($password), 'updated_at' => now()];
        if ($existing) {
            DB::table('admins')->where('id', $existing->id)->update($row);
            AuthSessions::endAll(AuthSessions::ADMIN, $existing->id);
            $this->info("Updated {$email} ({$role}).");
        } else {
            DB::table('admins')->insert($row + ['email' => $email, 'created_at' => now()]);
            $this->info("Created {$email} ({$role}).");
        }

        return self::SUCCESS;
    }
}

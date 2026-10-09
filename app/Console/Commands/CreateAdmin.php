<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'revilo:admin {email?} {--local : Generate a private local development login}';

    protected $description = 'Create the first administrator securely; no shared default password';

    public function handle(): int
    {
        $local = $this->option('local');
        if ($local && ! app()->environment('local')) {
            $this->error('Local account creation is allowed only in APP_ENV=local.');

            return self::FAILURE;
        }
        $email = $this->argument('email') ?? ($local ? 'admin@revilo.test' : $this->ask('Admin email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->info('Account already exists; no credentials or permissions changed.');

            return self::SUCCESS;
        }
        $password = $local ? Str::random(24) : $this->secret('Password (at least 12 characters with letters and numbers)');
        if (! $password || strlen($password) < 12 || ! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            $this->error('Use at least 12 characters with letters and numbers.');

            return self::FAILURE;
        }
        $u = User::create(['name' => 'Revilo beheer', 'email' => $email, 'password' => $password]);
        $u->forceFill(['role' => 'admin', 'active' => true, 'email_verified_at' => now()])->save();
        if ($local) {
            file_put_contents(storage_path('app/private/local-admin.txt'), "Local Revilo administrator\nURL: ".route('login', ['locale' => 'nl'])."\nEmail: ".$email."\nPassword: ".$password."\nDevelopment only. Keep this file private.\n");
            $this->info('Administrator created. Credentials are in storage/app/private/local-admin.txt (Git-ignored).');
        } else {
            $this->info('Administrator created.');
        }

        return self::SUCCESS;
    }
}

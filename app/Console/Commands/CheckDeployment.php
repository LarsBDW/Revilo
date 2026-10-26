<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckDeployment extends Command
{
    protected $signature = 'revilo:deployment-check {--config-only : Check configuration before database migrations}';

    protected $description = 'Check public server configuration without displaying credentials';

    public function handle(): int
    {
        $url = parse_url((string) config('app.url')) ?: [];
        $host = strtolower($url['host'] ?? '');
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $checks = [
            'Production environment' => app()->environment('production'),
            'Debug output disabled' => ! config('app.debug'),
            'Public HTTPS address configured' => ($url['scheme'] ?? '') === 'https'
                && str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)
                && ! preg_match('/(?:^|\.)(?:localhost|local|test|invalid)$/', $host),
            'Valid application encryption key' => is_string($key) && Encrypter::supported($key, config('app.cipher')),
            'Secure encrypted sessions' => config('session.secure') && config('session.encrypt'),
            'Test checkout disabled' => ! config('revilo.test_checkout'),
            'Database queue enabled' => config('queue.default') === 'database',
            'Restricted MySQL account configured' => config('database.default') === 'mysql'
                && ! in_array(config('database.connections.mysql.username'), [null, '', 'root'], true)
                && filled(config('database.connections.mysql.password')),
        ];
        $passed = true;
        foreach ($checks as $label => $valid) {
            if ($valid) {
                $this->line('OK: '.$label);
            } else {
                $this->error('FAIL: '.$label);
                $passed = false;
            }
        }
        if (! $this->option('config-only')) {
            try {
                DB::select('SELECT 1');
                foreach (['users', 'product_models', 'settings', 'jobs', 'failed_jobs', 'sessions'] as $table) {
                    if (! Schema::hasTable($table)) {
                        throw new \RuntimeException('Required schema is missing.');
                    }
                }
                $this->line('OK: Database connection and required tables');
            } catch (\Throwable) {
                $this->error('FAIL: Database connection or required tables. Check the private server configuration and run migrations.');
                $passed = false;
            }
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->warn('Email preview mode: no real emails are sent.');
        }
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            $this->warn('Google sign-in is not configured on this server.');
        } elseif (config('services.google.redirect') !== rtrim(config('app.url'), '/').'/auth/google/callback') {
            $this->error('FAIL: Google callback must match the public HTTPS address.');
            $passed = false;
        }
        if (config('revilo.public_preview')) {
            $this->line('Public preview: search engine indexing is disabled.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}

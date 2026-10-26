<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    private function proxyProbe(): void
    {
        Route::get('/_proxy-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'callback' => url('/auth/google/callback'),
            'host' => $request->getHost(),
            'ip' => $request->ip(),
        ]));
    }

    public function test_direct_requests_ignore_forwarded_https_by_default(): void
    {
        $this->proxyProbe();
        config(['trustedproxy.proxies' => '']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://shop.example.org/_proxy-probe')
            ->assertOk()->assertJsonPath('secure', false)
            ->assertJsonPath('callback', 'http://shop.example.org/auth/google/callback');
    }

    public function test_hosting_proxy_enables_https_without_trusting_forwarded_host_or_ip(): void
    {
        $this->proxyProbe();
        config(['trustedproxy.proxies' => '*']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'attacker.invalid',
                'X-Forwarded-For' => '203.0.113.5',
            ])->get('http://shop.example.org/_proxy-probe')
            ->assertOk()->assertJsonPath('secure', true)
            ->assertJsonPath('callback', 'https://shop.example.org/auth/google/callback')
            ->assertJsonPath('host', 'shop.example.org')
            ->assertJsonPath('ip', '192.0.2.10');
    }

    public function test_an_unlisted_proxy_cannot_change_the_request_protocol(): void
    {
        $this->proxyProbe();
        config(['trustedproxy.proxies' => '198.51.100.0/24']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://shop.example.org/_proxy-probe')
            ->assertOk()->assertJsonPath('secure', false);
    }

    private function serverConfiguration(): void
    {
        $this->app->instance('env', 'production');
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://shop.example.org',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'session.secure' => true,
            'session.encrypt' => true,
            'queue.default' => 'database',
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'revilo',
            'database.connections.mysql.password' => 'fictional-test-secret',
            'revilo.test_checkout' => false,
            'revilo.public_preview' => true,
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);
    }

    public function test_public_preview_is_excluded_from_search_indexing(): void
    {
        config(['revilo.public_preview' => true]);
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        config(['revilo.public_preview' => false]);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_public_configuration_passes_with_preview_email_and_no_google_client(): void
    {
        $this->serverConfiguration();
        $this->artisan('revilo:deployment-check', ['--config-only' => true])->expectsOutputToContain('no real emails')->assertSuccessful();
    }

    public function test_unsafe_public_configuration_is_rejected_without_displaying_secrets(): void
    {
        $this->serverConfiguration();
        config(['app.debug' => true, 'app.url' => 'http://127.0.0.1:8000', 'session.secure' => false, 'revilo.test_checkout' => true]);
        $this->artisan('revilo:deployment-check', ['--config-only' => true])
            ->expectsOutputToContain('FAIL: Debug output disabled')
            ->expectsOutputToContain('FAIL: Public HTTPS address configured')
            ->expectsOutputToContain('FAIL: Test checkout disabled')->assertFailed();
    }

    public function test_google_callback_must_use_the_server_address(): void
    {
        $this->serverConfiguration();
        config(['services.google.client_id' => 'fictional', 'services.google.client_secret' => 'fictional', 'services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback']);
        $this->artisan('revilo:deployment-check', ['--config-only' => true])->expectsOutputToContain('FAIL: Google callback')->assertFailed();
    }
}

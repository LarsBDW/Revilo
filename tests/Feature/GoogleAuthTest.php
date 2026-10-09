<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\ProductModel;
use App\Models\User;
use App\Services\CartService;
use App\Services\CatalogService;
use Database\Seeders\ShopSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.google.client_id' => 'test.apps.googleusercontent.com', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
    }

    private function provider(array $claims = []): MockHandler
    {
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'fictional-token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(array_merge(['sub' => 'google-123', 'name' => 'Google Test Customer', 'email' => 'google-customer@example.test', 'email_verified' => true], $claims))),
        ]);
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(fn () => (new GoogleProvider(request(), 'test.apps.googleusercontent.com', 'test-secret', 'http://localhost/auth/google/callback'))->setHttpClient(new Client(['handler' => HandlerStack::create($handler)])));

        return $handler;
    }

    private function completeCallback(string $intent = 'login', ?User $linking = null, string $state = 'expected-state')
    {
        return $this->withSession(['locale' => 'en', 'state' => 'expected-state', 'code_verifier' => 'fictional-code-verifier', 'google.oauth' => ['intent' => $intent, 'user_id' => $linking?->id, 'expires_at' => now()->addMinutes(10)->timestamp]])->get('/auth/google/callback?code=fictional-code&state='.$state);
    }

    public function test_login_and_registration_have_distinct_google_buttons_in_every_language(): void
    {
        foreach (['nl' => ['Inloggen met Google', 'Registreren met Google'], 'en' => ['Sign in with Google', 'Sign up with Google'], 'de' => ['Mit Google anmelden', 'Mit Google registrieren']] as $locale => $labels) {
            $this->get('/'.$locale.'/login')->assertOk()->assertSee($labels[0])->assertSee('intent=login');
            $this->get('/'.$locale.'/register')->assertOk()->assertSee($labels[1])->assertSee('intent=register');
        }
    }

    public function test_oauth_redirect_uses_state_pkce_and_only_identity_scopes(): void
    {
        $response = $this->withSession(['locale' => 'en'])->get('/auth/google?intent=register')->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
        $location = $response->headers->get('Location');
        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $response->assertSessionHas('state', $query['state'])->assertSessionHas('code_verifier')->assertSessionHas('google.oauth.intent', 'register');
    }

    public function test_new_google_account_requires_terms_then_creates_verified_customer(): void
    {
        $this->provider();
        $this->completeCallback('register')->assertRedirect('/en/register/google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->get('/en/register/google')->assertOk()->assertSee('google-customer@example.test')->assertSee('Google Test Customer')->assertHeader('Cache-Control', 'no-store, private')->assertSee('noindex,follow');
        $this->from('/en/register/google')->post('/en/register/google', [])->assertSessionHasErrors('terms');
        $this->assertDatabaseCount('users', 0);
        $this->post('/en/register/google', ['terms' => 1, 'name' => 'Tampered', 'email' => 'tampered@example.test', 'google_id' => 'wrong', 'role' => 'admin'])->assertRedirect('/en/account')->assertSessionMissing('google.pending');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Google Test Customer', $user->name);
        $this->assertSame('google-customer@example.test', $user->email);
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('customer', $user->role);
        $this->assertTrue($user->active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
    }

    public function test_returning_customer_signs_in_and_keeps_intended_destination_and_cart(): void
    {
        $this->seed(ShopSeeder::class);
        $model = ProductModel::firstOrFail();
        $cart = app(CartService::class)->current();
        $configuration = app(CatalogService::class)->defaultConfiguration($model);
        app(CartService::class)->add($model, $configuration, 1);
        $user = User::factory()->create(['email' => 'google-customer@example.test']);
        $user->forceFill(['google_id' => 'google-123'])->save();
        $this->provider();
        $this->withSession(['cart_token' => $cart->token, 'url.intended' => url('/en/checkout')]);
        $this->completeCallback()->assertRedirect('/en/checkout');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
        $this->assertSame(1, (int) Cart::where('user_id', $user->id)->firstOrFail()->items()->sum('quantity'));
    }

    public function test_password_account_is_not_silently_linked_or_taken_over(): void
    {
        $user = User::factory()->create(['email' => 'google-customer@example.test']);
        $this->provider();
        $this->completeCallback()->assertRedirect('/en/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_signed_in_owner_can_explicitly_link_the_matching_google_account(): void
    {
        $user = User::factory()->create(['email' => 'google-customer@example.test', 'email_verified_at' => null]);
        $this->actingAs($user);
        $this->provider();
        $this->completeCallback('link', $user)->assertRedirect('/en/account')->assertSessionHas('success');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get('/en/account')->assertOk()->assertSee('Google is connected.');
    }

    public function test_linking_cannot_change_email_replace_identity_or_switch_accounts(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $user->forceFill(['google_id' => 'original-google-id'])->save();
        $this->actingAs($user);
        $this->provider(['email' => 'owner@example.test']);
        $this->completeCallback('link', $user)->assertRedirect('/en/account')->assertSessionHasErrors('email');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('original-google-id', $user->fresh()->google_id);
        $this->assertSame('owner@example.test', $user->fresh()->email);
    }

    public function test_linking_rejects_a_different_email_and_logged_out_owner(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $this->actingAs($user);
        $this->provider();
        $this->completeCallback('link', $user)->assertRedirect('/en/account')->assertSessionHasErrors('email');
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_unverified_google_email_and_disabled_accounts_cannot_sign_in(): void
    {
        $this->provider(['email_verified' => false]);
        $this->completeCallback()->assertRedirect('/en/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_disabled_google_account_cannot_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'google-customer@example.test', 'active' => false]);
        $user->forceFill(['google_id' => 'google-123'])->save();
        $this->provider();
        $this->completeCallback()->assertRedirect('/en/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_invalid_oauth_state_is_rejected_before_any_google_api_request(): void
    {
        $handler = $this->provider();
        $this->completeCallback('login', null, 'wrong-state')->assertRedirect('/en/login')->assertSessionHasErrors('email');
        $this->assertCount(2, $handler);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cancelled_google_authorization_is_a_local_error_without_account_creation(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->withSession(['locale' => 'de', 'state' => 'old-state', 'google.oauth' => ['intent' => 'register', 'expires_at' => now()->addMinutes(10)->timestamp]])->get('/auth/google/callback?error=access_denied')->assertRedirect('/de/register')->assertSessionHasErrors('email')->assertSessionMissing('state');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_or_missing_signup_identity_cannot_create_an_account(): void
    {
        $this->get('/en/register/google')->assertRedirect('/en/register')->assertSessionHasErrors('email');
        $this->withSession(['google.pending' => ['id' => 'expired-id', 'email' => 'expired@example.test', 'name' => 'Expired', 'expires_at' => now()->subMinute()->timestamp]])->post('/en/register/google', ['terms' => 1])->assertRedirect('/en/register')->assertSessionMissing('google.pending');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_credentials_show_an_honest_setup_message(): void
    {
        config(['services.google.client_secret' => null]);
        $this->get('/en/register')->assertOk()->assertSee('Google sign-in is awaiting configuration.');
        $this->get('/auth/google?intent=register')->assertStatus(503)->assertSee('create an account with your email');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_linking_requires_an_authenticated_owner(): void
    {
        $this->withSession(['locale' => 'en'])->get('/auth/google?intent=link')->assertRedirect('/en/login');
        $this->provider();
        $this->completeCallback('link', User::factory()->create())->assertRedirect('/en/account')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_expired_callback_context_is_rejected_before_using_the_provider(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->withSession(['locale' => 'en', 'state' => 'old-state', 'google.oauth' => ['intent' => 'login', 'expires_at' => now()->subMinute()->timestamp]])->get('/auth/google/callback?state=old-state&code=fictional')->assertRedirect('/en/login')->assertSessionHasErrors('email')->assertSessionMissing('state');
        $this->assertGuest();
    }

    public function test_account_created_during_pending_signup_is_rechecked_without_takeover(): void
    {
        $this->provider();
        $this->completeCallback('register')->assertRedirect('/en/register/google');
        $passwordAccount = User::factory()->create(['email' => 'google-customer@example.test']);
        $this->post('/en/register/google', ['terms' => 1])->assertRedirect('/en/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($passwordAccount->fresh()->google_id);
    }

    public function test_identity_created_during_pending_signup_reuses_the_verified_customer(): void
    {
        $this->provider();
        $this->completeCallback('register')->assertRedirect('/en/register/google');
        $customer = User::factory()->create(['email' => 'google-customer@example.test']);
        $customer->forceFill(['google_id' => 'google-123'])->save();
        $this->post('/en/register/google', ['terms' => 1])->assertRedirect('/en/account');
        $this->assertAuthenticatedAs($customer);
        $this->assertDatabaseCount('users', 1);
    }
}

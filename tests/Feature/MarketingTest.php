<?php

namespace Tests\Feature;

use App\Models\EmailRecord;
use App\Models\InventoryUnit;
use App\Models\ProductModel;
use App\Models\Subscription;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\MarketingService;
use Database\Seeders\ShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(ShopSeeder::class);
    }

    private function subscribe(array $extra = []): Subscription
    {
        return Subscription::create(array_merge(['email' => Str::random(8).'@example.test', 'type' => 'newsletter', 'locale' => 'en', 'token' => Str::random(64), 'consent_at' => now(), 'confirmed_at' => now()], $extra));
    }

    public function test_confirmation_and_unsubscribe_require_valid_subscription_tokens(): void
    {
        $s = $this->subscribe(['confirmed_at' => null]);
        $this->get('/en/subscriptions/'.$s->token.'/confirm')->assertForbidden();
        $this->get(URL::temporarySignedRoute('subscriptions.confirm', now()->addHour(), ['locale' => 'en', 'token' => $s->token]))->assertRedirect();
        $this->assertNotNull($s->fresh()->confirmed_at);
        $this->get('/en/subscriptions/'.$s->token.'/unsubscribe')->assertOk();
        $this->assertNotNull($s->fresh()->unsubscribed_at);
    }

    public function test_campaign_excludes_unconfirmed_and_unsubscribed_recipients_and_is_idempotent(): void
    {
        $good = $this->subscribe();
        $this->subscribe(['confirmed_at' => null]);
        $this->subscribe(['unsubscribed_at' => now()]);
        $this->subscribe(['locale' => 'de']);
        DB::table('email_campaigns')->insert(['name' => 'Offer', 'subject' => 'An offer', 'body' => 'A sample offer', 'segment' => 'en', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        $s = app(MarketingService::class);
        $this->assertSame(1, $s->campaigns());
        $this->assertSame(0, $s->campaigns());
        $this->assertDatabaseHas('email_records', ['recipient' => $good->email, 'status' => 'preview']);
        $this->assertStringContainsString('/unsubscribe', EmailRecord::first()->html);
    }

    public function test_stock_notices_require_fulfillable_configuration_and_send_only_once(): void
    {
        $m = ProductModel::first();
        $this->subscribe(['type' => 'stock', 'product_model_id' => $m->id, 'configuration' => ['ram_gb' => 64]]);
        $good = $this->subscribe(['type' => 'stock', 'product_model_id' => $m->id, 'configuration' => ['ram_gb' => 32]]);
        $s = app(MarketingService::class);
        $this->assertSame(1, $s->stock());
        $this->assertSame(0, $s->stock());
        $this->assertNotNull($good->fresh()->notified_at);
        $this->assertSame(1, EmailRecord::count());
    }

    public function test_abandoned_cart_reminders_skip_missing_consent_and_unavailable_stock(): void
    {
        $m = ProductModel::first();
        $c = app(CatalogService::class)->defaultConfiguration($m);
        app(CartService::class)->add($m, $c, 1);
        $cart = app(CartService::class)->current();
        $cart->update(['email' => 'buyer@example.test', 'reminder_token' => Str::random(64), 'reminder_consent' => false]);
        $this->travel(35)->minutes();
        $s = app(MarketingService::class);
        $this->assertSame(0, $s->abandoned());
        $cart->timestamps = false;
        $cart->update(['reminder_consent' => true]);
        $this->assertSame(1, $s->abandoned());
        $this->assertSame(0, $s->abandoned());
        $this->travel(2)->days();
        InventoryUnit::where('product_model_id', $m->id)->update(['status' => 'sold']);
        $this->assertSame(0, $s->abandoned());
    }
}

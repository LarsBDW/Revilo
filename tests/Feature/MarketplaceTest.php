<?php

namespace Tests\Feature;

use App\Models\InventoryUnit;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceDraft;
use App\Models\ProductModel;
use App\Models\User;
use App\Services\MarketplaceCatalog;
use App\Services\MarketplaceGateway;
use App\Services\MarketplaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Storage::fake('public');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => 'admin'])->save();
        config(['app.url' => 'https://revilo.example']);
    }

    private function fields(): array
    {
        return ['title' => 'HP laptop i5 | 8GB | 256GB SSD', 'description' => 'Echte HP laptop met 8GB RAM en 256GB SSD.', 'priceCents' => 19950, 'condition' => 'USED_GOOD', 'conditionDescription' => 'Lichte gebruikssporen', 'notes' => 'PRIVATE INTERNAL NOTE', 'photos' => [], 'channels' => ['ebay'], 'ebayCategoryId' => '175672', 'mpCategoryId' => '', 'belgiumCategoryId' => '', 'ebayAspects' => ['Brand' => ['HP']], 'mpAttributes' => [], 'belgiumAttributes' => [], 'brand' => 'HP', 'modelName' => 'EliteBook Test', 'websiteCategory' => 'business', 'websitePublished' => true, 'processor' => 'Intel Core i5-8350U', 'processorFamily' => 'i5', 'processorGeneration' => 8, 'ramGb' => 8, 'ssdGb' => 256, 'screenInches' => 14, 'resolution' => 'Full HD', 'graphics' => 'Intel UHD 620', 'keyboardLayout' => 'QWERTY', 'operatingSystem' => 'Windows 11 Pro', 'grade' => 'B'];
    }

    private function photo(): array
    {
        $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        return $this->actingAs($this->admin)->postJson('/admin/marketplaces/api/photos', ['base64' => base64_encode($data), 'name' => 'laptop.png'])->assertOk()->json();
    }

    private function create(): array
    {
        return $this->actingAs($this->admin)->postJson('/admin/marketplaces/api/products', array_merge($this->fields(), ['photos' => [$this->photo()]]))->assertOk()->json();
    }

    private function ebay(): void
    {
        MarketplaceConnection::create(['provider' => 'ebay', 'credentials' => ['clientId' => 'app', 'clientSecret' => 'PRIVATE-SECRET', 'refreshToken' => 'PRIVATE-REFRESH', 'sandbox' => false, 'marketplaceId' => 'EBAY_NL', 'locale' => 'nl-NL', 'locationKey' => 'shop', 'fulfillmentPolicyId' => '1', 'paymentPolicyId' => '2', 'returnPolicyId' => '3']]);
    }

    public function test_admin_area_uses_existing_access_control(): void
    {
        $this->get('/admin/marketplaces')->assertRedirect('/nl/login');
        $this->getJson('/admin/marketplaces/api/products')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->get('/admin/marketplaces')->assertForbidden();
        $this->getJson('/admin/marketplaces/api/connections/ebay')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/marketplaces')->assertOk()->assertSee('Marketplacebeheer');
        $this->get('/admin')->assertOk()->assertSee('Marketplaces');
    }

    public function test_new_laptop_is_added_to_webshop_and_physical_inventory_once(): void
    {
        $p = $this->create();
        $this->assertDatabaseCount('product_models', 1);
        $this->assertDatabaseCount('inventory_units', 1);
        $this->assertDatabaseCount('marketplace_drafts', 1);
        $unit = InventoryUnit::first();
        $this->assertSame('available', $unit->status);
        $this->assertSame(19950, $unit->selling_cents);
        $this->assertTrue($unit->model->published);
        $this->get('/nl/laptops/'.$unit->model->slug)->assertOk()->assertSee('EliteBook Test')->assertDontSee('PRIVATE INTERNAL NOTE');
        $this->get('/nl/laptops')->assertOk()->assertSee('EliteBook Test');
        $this->get('/marketplace-photos/'.$p['photos'][0]['file'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $updated = array_merge($p, ['priceCents' => 17500, 'description' => 'Bijgewerkte omschrijving op webshop en advertenties.']);
        $this->postJson('/admin/marketplaces/api/products', $updated)->assertOk();
        $this->assertDatabaseCount('inventory_units', 1);
        $this->assertSame(17500, $unit->fresh()->selling_cents);
        $this->assertSame($updated['description'], $unit->fresh()->model->description['nl']);
    }

    public function test_existing_inventory_attach_is_idempotent(): void
    {
        $p = $this->create();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/admin/marketplaces/api/import-unit', ['unitId' => $p['inventoryUnitId']])->assertOk()->assertJsonPath('id', $p['id']);
        }
        $this->assertDatabaseCount('inventory_units', 1);
        $this->assertDatabaseCount('product_models', 1);
        $this->assertDatabaseCount('marketplace_drafts', 1);
    }

    public function test_reserved_sold_and_stale_stock_cannot_be_overwritten(): void
    {
        $p = $this->create();
        $unit = InventoryUnit::first();
        $unit->update(['status' => 'reserved']);
        $this->postJson('/admin/marketplaces/api/products', $p)->assertUnprocessable();
        $reserved = app(MarketplaceCatalog::class)->serialize(MarketplaceDraft::first());
        $this->postJson('/admin/marketplaces/api/products', $reserved)->assertUnprocessable();
        $this->postJson('/admin/marketplaces/api/products/'.$p['id'].'/sold')->assertUnprocessable();
        $this->assertSame('reserved', $unit->fresh()->status);
        $unit->update(['status' => 'sold']);
        $this->postJson('/admin/marketplaces/api/products', app(MarketplaceCatalog::class)->serialize(MarketplaceDraft::first()))->assertUnprocessable();
    }

    public function test_shared_model_edit_does_not_change_other_laptops(): void
    {
        $p = $this->create();
        $first = InventoryUnit::first();
        $second = $first->replicate();
        $second->inventory_id = 'SECOND-UNIT';
        $second->save();
        $modelId = $first->product_model_id;
        $this->postJson('/admin/marketplaces/api/products', array_merge($p, ['modelName' => 'Personal model', 'description' => 'Only this laptop changes.']))->assertOk();
        $this->assertNotSame($modelId, $first->fresh()->product_model_id);
        $this->assertSame($modelId, $second->fresh()->product_model_id);
        $this->assertSame('EliteBook Test', $second->fresh()->model->name);
        $this->assertSame('Echte HP laptop met 8GB RAM en 256GB SSD.', $second->fresh()->model->description['nl']);
    }

    public function test_model_discount_keeps_webshop_and_advertised_price_equal(): void
    {
        $p = $this->create();
        InventoryUnit::first()->model->update(['discount_percent' => 15]);
        $current = app(MarketplaceCatalog::class)->serialize(MarketplaceDraft::first());
        $saved = $this->postJson('/admin/marketplaces/api/products', array_merge($current, ['priceCents' => 17999]))->assertOk()->json();
        $this->assertSame(17999, $saved['priceCents']);
        $unit = InventoryUnit::first();
        $this->assertSame(17999, $unit->selling_cents - intdiv($unit->selling_cents * 15, 100));
    }

    public function test_secrets_are_encrypted_and_not_returned_by_connection_endpoints(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/marketplaces/api/connections/ebay', ['clientId' => 'app', 'clientSecret' => 'SENSITIVE-SECRET', 'refreshToken' => 'SENSITIVE-REFRESH', 'sandbox' => true, 'marketplaceId' => 'EBAY_NL'])->assertOk();
        $raw = DB::table('marketplace_connections')->value('credentials');
        $this->assertStringNotContainsString('SENSITIVE-SECRET', $raw);
        $this->assertStringNotContainsString('SENSITIVE-REFRESH', $raw);
        $this->getJson('/admin/marketplaces/api/connections/ebay')->assertJsonPath('clientSecret', '')->assertJsonPath('clientSecretSaved', true)->assertDontSee('SENSITIVE-REFRESH');
        $this->assertArrayNotHasKey('credentials', MarketplaceConnection::first()->toArray());
        Http::assertNothingSent();
    }

    public function test_ebay_create_update_and_withdraw_use_native_sku_and_public_fields_only(): void
    {
        $p = $this->create();
        $draft = MarketplaceDraft::first();
        $this->ebay();
        $exists = false;
        $published = false;
        Http::fake(function ($request) use (&$exists, &$published, $p) {
            $url = $request->url();
            if (str_contains($url, '/oauth2/token')) {
                return Http::response(['access_token' => 'token']);
            }
            if (str_contains($url, '/offer?')) {
                return Http::response(['offers' => $exists ? [['offerId' => '100']] : []]);
            }
            if (str_ends_with($url, '/offer') && $request->method() === 'POST') {
                $exists = true;
                return Http::response(['offerId' => '100']);
            }
            if (str_ends_with($url, '/offer/100/publish')) {
                $published = true;
                return Http::response(['listingId' => '200']);
            }
            if (str_ends_with($url, '/offer/100') && $request->method() === 'GET') {
                return Http::response(['sku' => $p['sku'], 'marketplaceId' => 'EBAY_NL', 'status' => $published ? 'PUBLISHED' : 'UNPUBLISHED', 'availableQuantity' => 1, 'listing' => ['listingId' => '200']]);
            }
            return Http::response([], 200);
        });
        $service = app(MarketplaceService::class);
        $action = $service->enqueue($draft, ['ebay', 'facebook'], 'publish');
        $service->process($action->id);
        $this->assertSame('completed', $action->fresh()->status);
        $this->assertSame('live', app(MarketplaceGateway::class)->listing($draft, 'ebay')['status']);
        $this->assertSame('manual', app(MarketplaceGateway::class)->listing($draft, 'facebook')['status']);
        $service->process($service->enqueue($draft, ['ebay'], 'update')->id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_item/REVILO-UNIT-') && $r->method() === 'PUT' && $r['product']['imageUrls'][0] === 'https://revilo.example/marketplace-photos/'.$p['photos'][0]['file']);
        $creates = Http::recorded(fn ($r) => str_ends_with($r->url(), '/offer') && $r->method() === 'POST');
        $this->assertCount(1, $creates);
        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/oauth2/token')) {
                $this->assertStringNotContainsString('PRIVATE INTERNAL NOTE', $request->body());
                $this->assertStringNotContainsString('purchase_cents', $request->body());
            }
        }
        $withdraw = $service->sold($draft);
        $service->process($withdraw->id);
        $this->assertSame('sold', InventoryUnit::first()->status);
        $this->assertSame('ended', app(MarketplaceGateway::class)->listing($draft, 'ebay')['status']);
    }

    public function test_invalid_images_and_unconfigured_publish_do_not_send_requests(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/marketplaces/api/photos', ['base64' => base64_encode('<svg><script>bad()</script></svg>')])->assertUnprocessable();
        $p = $this->create();
        $this->postJson('/admin/marketplaces/api/products/'.$p['id'].'/operation', ['action' => 'publish', 'channels' => ['ebay']])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_units', 1);
        $this->assertTrue(ProductModel::first()->published);
        Http::assertNothingSent();
    }
}

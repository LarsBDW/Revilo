<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\EmailRecord;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\ProductModel;
use App\Models\User;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\CsvImportService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Database\Seeders\ShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['revilo.test_checkout' => true]);
        $this->seed(ShopSeeder::class);
    }

    private function customer(): array
    {
        return ['email' => 'buyer@example.test', 'name' => 'Test Buyer', 'street' => 'Teststraat', 'house_number' => '12', 'postal_code' => '1234 AB', 'city' => 'Amsterdam', 'country' => 'NL'];
    }

    public function test_search_understands_brand_processor_and_memory_storage_capacities(): void
    {
        $results = app(CatalogService::class)->matching(['q' => 'Dell i5 32GB RAM 1TB SSD', 'in_stock' => 1]);
        $this->assertCount(2, $results);
        foreach ($results as $s) {
            $this->assertSame('Dell', $s['model']->brand);
            $this->assertSame(32, $s['configuration']['ram_gb']);
            $this->assertSame(1024, $s['configuration']['ssd_gb']);
        }
    }

    private function add(): Cart
    {
        $m = ProductModel::first();
        $c = app(CatalogService::class)->defaultConfiguration($m);
        app(CartService::class)->add($m, $c, 1);

        return app(CartService::class)->current();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->save();

        return $u;
    }

    public function test_every_public_page_renders_in_three_languages(): void
    {
        foreach (['nl', 'en', 'de'] as $locale) {
            foreach (['', 'laptops', 'deals', 'login', 'register', 'forgot-password', 'wishlist', 'compare', 'laptop-finder', 'about', 'contact', 'faq', 'shipping', 'warranty', 'privacy', 'terms', 'cookies', 'process', 'condition', 'business', 'tracking', 'trade-in', 'cart'] as $path) {
                $this->get('/'.$locale.($path ? '/'.$path : ''))->assertOk();
            }
        }
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
    }

    public function test_product_and_quote_do_not_leak_private_inventory(): void
    {
        $m = ProductModel::first();
        $this->get('/en/laptops/'.$m->slug)->assertOk()->assertDontSee('DEMO-PRIVATE');
        $c = app(CatalogService::class)->defaultConfiguration($m);
        $r = $this->getJson('/en/laptops/'.$m->slug.'/quote?'.http_build_query($c))->assertOk();
        $this->assertArrayNotHasKey('purchase_cents', $r->json());
        $this->assertStringNotContainsString('serial_number', $r->getContent());
    }

    public function test_filter_cards_show_the_matching_upgrade_and_price(): void
    {
        $s = app(CatalogService::class)->matching(['ram' => [32], 'ssd' => [1024]])->first();
        $this->assertSame(32, $s['configuration']['ram_gb']);
        $this->assertSame(1024, $s['configuration']['ssd_gb']);
        $this->assertGreaterThan(20000, $s['price_cents']);
        $this->get('/en/laptops?ram[]=32&ssd[]=1024')->assertOk()->assertSee('32 GB RAM')->assertSee('1024 GB SSD');
    }

    public function test_cart_price_tampering_is_ignored_and_checkout_is_idempotent(): void
    {
        $cart = $this->add();
        $expected = app(CartService::class)->totals($cart);
        $key = Str::random(64);
        $o = app(OrderService::class)->checkout($cart, array_merge($this->customer(), ['total_cents' => 1]), $key);
        $again = app(OrderService::class)->checkout($cart, $this->customer(), $key);
        $this->assertSame($o->id, $again->id);
        $this->assertSame($expected['total_cents'], $o->total_cents);
        $this->assertSame(1, Order::count());
        $this->assertTrue($o->is_test);
        $this->assertNull($o->paid_at);
        $this->assertDatabaseHas('payments', ['order_id' => $o->id, 'provider_confirmed' => false]);
        $this->assertSame(1, EmailRecord::count());
        $this->assertSame('preview', EmailRecord::first()->status);
        $this->assertSame('reserved', $o->items->first()->unit->status);
    }

    public function test_cancellation_releases_inventory_and_coupon_once(): void
    {
        $cart = $this->add();
        $coupon = Coupon::first();
        $coupon->update(['minimum_cents' => 0]);
        $cart->update(['coupon_code' => $coupon->code]);
        $o = app(OrderService::class)->checkout($cart, $this->customer(), Str::random(64));
        $this->assertSame(1, $coupon->fresh()->uses);
        app(InventoryService::class)->release($o);
        app(InventoryService::class)->release($o);
        $this->assertSame(0, $coupon->fresh()->uses);
        $this->assertSame('available', $o->items->first()->unit->fresh()->status);
    }

    public function test_guest_order_and_invoice_require_secure_link(): void
    {
        $o = app(OrderService::class)->checkout($this->add(), $this->customer(), Str::random(64));
        $this->get('/nl/orders/'.$o->public_id)->assertForbidden();
        $this->get(URL::temporarySignedRoute('orders.show', now()->addHour(), ['locale' => 'nl', 'order' => $o->public_id]))->assertOk()->assertSee($o->number)->assertDontSee('DEMO-PRIVATE');
        $this->get(URL::temporarySignedRoute('orders.invoice', now()->addHour(), ['locale' => 'nl', 'order' => $o->public_id]))->assertOk()->assertSee('PRO FORMA');
    }

    public function test_another_customer_cannot_read_order(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $o = app(OrderService::class)->checkout($this->add(), $this->customer(), Str::random(64));
        $this->actingAs(User::factory()->create())->get('/nl/orders/'.$o->public_id)->assertForbidden();
        $this->actingAs($owner)->get('/nl/orders/'.$o->public_id)->assertOk();
    }

    public function test_test_fulfillment_consumes_reservation_but_never_records_real_revenue(): void
    {
        $o = app(OrderService::class)->checkout($this->add(), $this->customer(), Str::random(64));
        $s = app(OrderService::class);
        $s->transition($o, 'processing');
        $s->transition($o->fresh(), 'preparing_shipment');
        $s->transition($o->fresh(), 'shipped', ['carrier' => 'Test carrier', 'tracking_number' => 'TEST123', 'tracking_url' => 'https://example.test/tracking/TEST123']);
        $this->assertSame('sold', $o->items->first()->unit->fresh()->status);
        $this->assertSame('consumed', $o->reservations()->first()->status);
        $this->assertNull($o->fresh()->paid_at);
        $this->assertDatabaseHas('payments', ['order_id' => $o->id, 'provider_confirmed' => false, 'status' => 'simulated_complete']);
    }

    public function test_expiry_releases_laptop_without_double_counting(): void
    {
        $o = app(OrderService::class)->checkout($this->add(), $this->customer(), Str::random(64));
        $this->travel(31)->minutes();
        $this->assertSame(1, app(InventoryService::class)->expire());
        $this->assertSame(0, app(InventoryService::class)->expire());
        $this->assertSame('available', $o->items->first()->unit->fresh()->status);
    }

    public function test_checkout_rejects_invalid_country_and_postcode(): void
    {
        $this->add();
        $this->get('/nl/checkout')->assertOk();
        $key = session('checkout_key');
        $d = array_merge($this->customer(), ['terms' => 1, 'test_acknowledgement' => 1, 'idempotency_key' => $key, 'country' => 'US']);
        $this->post('/nl/checkout', $d)->assertSessionHasErrors('country');
        $d['country'] = 'BE';
        $d['postal_code'] = '1234 AB';
        $this->post('/nl/checkout', $d)->assertSessionHasErrors('postal_code');
        $this->assertSame(0, Order::count());
    }

    public function test_disabled_checkout_cannot_create_order(): void
    {
        $this->add();
        config(['revilo.test_checkout' => false]);
        $this->get('/nl/checkout')->assertForbidden();
        $this->assertSame(0, Order::count());
    }

    public function test_admin_pages_render_and_customer_has_no_admin_access(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs($this->admin());
        foreach (['', 'products', 'inventory', 'components', 'discounts', 'products/create', 'inventory/create', 'components/create', 'discounts/create', 'inventory/'.InventoryUnit::first()->id.'/edit', 'inventory/import', 'orders', 'content', 'emails', 'analytics', 'settings', 'data/customers', 'data/returns', 'data/reviews', 'data/business', 'data/subscribers', 'data/audit', 'data/imports', 'data/reports'] as $path) {
            $this->get('/admin'.($path ? '/'.$path : ''))->assertOk();
        }
    }

    public function test_registration_cannot_set_role_and_prepares_verification(): void
    {
        $this->post('/en/register', ['name' => 'Example Customer', 'email' => 'new@example.test', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123', 'terms' => 1, 'role' => 'admin'])->assertRedirect('/en/account');
        $u = User::where('email', 'new@example.test')->first();
        $this->assertSame('customer', $u->role);
        $this->assertNull($u->email_verified_at);
        $this->assertSame('preview', EmailRecord::first()->status);
    }

    public function test_account_order_and_email_preview_render(): void
    {
        $u = $this->admin();
        $this->actingAs($u);
        $o = app(OrderService::class)->checkout($this->add(), $this->customer(), Str::random(64));
        $this->get('/en/account')->assertOk();
        $this->get('/admin/orders/'.$o->public_id)->assertOk();
        $this->get('/admin/emails/'.EmailRecord::first()->id)->assertOk();
    }

    public function test_contact_business_and_newsletter_store_validated_records(): void
    {
        $this->post('/en/contact', ['name' => 'Customer', 'email' => 'contact@example.test', 'message' => 'A question about a laptop.', 'privacy' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inquiries', ['type' => 'contact', 'email' => 'contact@example.test']);
        $this->post('/en/business', ['name' => 'Buyer', 'email' => 'buyer@example.test', 'organization' => 'School', 'quantity' => 5, 'ram' => 16, 'ssd' => 512, 'budget' => 300, 'country' => 'BE', 'privacy' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inquiries', ['type' => 'business']);
        $this->post('/en/subscriptions', ['email' => 'newsletter@example.test', 'consent' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscriptions', ['email' => 'newsletter@example.test', 'confirmed_at' => null]);
        $this->assertSame('preview', EmailRecord::latest('id')->first()->status);
    }

    public function test_full_http_purchase_and_admin_fulfillment_flow(): void
    {
        $m = ProductModel::first();
        $c = app(CatalogService::class)->defaultConfiguration($m);
        $this->get('/en/laptops?brand[]=Dell&ram[]=32&sort=price_asc')->assertOk();
        $this->get('/en/laptops/'.$m->slug)->assertOk();
        $c['ram_gb'] = 32;
        $c['ssd_gb'] = 1024;
        $q = $this->getJson('/en/laptops/'.$m->slug.'/quote?'.http_build_query($c))->assertOk()->json();
        $this->post('/en/cart/'.$m->slug, array_merge($c, ['quantity' => 1, 'price_cents' => 1]))->assertRedirect('/en/cart');
        $this->get('/en/cart')->assertOk()->assertSee('32 GB RAM');
        $this->get('/en/checkout')->assertOk();
        $response = $this->post('/en/checkout', array_merge($this->customer(), ['terms' => 1, 'test_acknowledgement' => 1, 'idempotency_key' => session('checkout_key')]))->assertSessionHasNoErrors()->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk();
        $o = Order::first();
        $this->assertSame($q['price_cents'], $o->subtotal_cents);
        $snapshot = $o->items->first()->snapshot;
        $m->update(['name' => 'Changed product']);
        $this->assertSame($snapshot, $o->items->first()->fresh()->snapshot);
        $this->actingAs($this->admin());
        $this->get('/admin/orders/'.$o->public_id)->assertOk();
        foreach (['processing', 'preparing_shipment', 'shipped', 'delivered'] as $status) {
            $this->patch('/admin/orders/'.$o->public_id, ['status' => $status, 'carrier' => 'Test', 'tracking_number' => 'TEST-TRACK', 'tracking_url' => 'https://example.test/tracking'])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame('delivered', $o->fresh()->status);
        $this->assertSame('sold', $o->items->first()->unit->fresh()->status);
        $this->assertNull($o->fresh()->paid_at);
    }

    public function test_changed_prices_require_checkout_review_again(): void
    {
        $this->add();
        $this->get('/nl/checkout')->assertOk();
        InventoryUnit::where('status', 'available')->increment('selling_cents', 100);
        $this->post('/nl/checkout', array_merge($this->customer(), ['terms' => 1, 'test_acknowledgement' => 1, 'idempotency_key' => session('checkout_key')]))->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_account_cannot_modify_another_customers_address_or_cart(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first);
        $cart = $this->add();
        $item = $cart->items->first();
        $address = DB::table('customer_addresses')->insertGetId(['user_id' => $first->id, 'label' => 'Home', 'address' => json_encode($this->customer()), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($second)->delete('/nl/account/addresses/'.$address)->assertForbidden();
        $this->patch('/nl/cart/items/'.$item->id, ['quantity' => 1])->assertForbidden();
    }

    public function test_return_to_inspection_requires_actual_inspection_notes(): void
    {
        $o = app(OrderService::class)->checkout($this->add(), $this->customer(), Str::random(64));
        $item = $o->items->first();
        $unit = $item->unit;
        $unit->update(['status' => 'sold']);
        $id = DB::table('return_requests')->insertGetId(['order_id' => $o->id, 'order_item_id' => $item->id, 'reason' => 'Sample return reason', 'status' => 'received', 'refund_status' => 'not_started', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin())->patch('/admin/data/returns/'.$id, ['status' => 'received', 'refund_status' => 'not_started', 'restock' => 1])->assertUnprocessable();
        $this->patch('/admin/data/returns/'.$id, ['status' => 'inspected', 'refund_status' => 'pending', 'restock' => 1, 'inspection_notes' => 'Received and inspected. Needs functional retest.'])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_inspection', $unit->fresh()->status);
    }

    public function test_csv_import_requires_duplicate_choice_and_reports_errors(): void
    {
        $u = $this->admin();
        $s = app(CsvImportService::class);
        $first = InventoryUnit::first();
        $row = ['Dell', 'Latitude 5490', $first->inventory_id, 'NEW-CSV-SERIAL', 'Intel Core i5-8350U', 'i5', '8', '8', '256', '14', 'Full HD', 'QWERTY', 'Windows 11 Pro', '90', 'A', '', '50,00', '10,00', '199,00', 'available', '2026-10-01', 'Private import note', 'standard'];
        $path = tempnam(sys_get_temp_dir(), 'revilo-csv-');
        $h = fopen($path, 'w');
        fputcsv($h, CsvImportService::FIELDS, ';', '"', '');
        fputcsv($h, $row, ';', '"', '');
        $row[2] = 'BAD-ID';
        $row[14] = 'D';
        fputcsv($h, $row, ';', '"', '');
        fclose($h);
        try {
            $p = $s->parse($path);
            $this->assertSame(1, $p['valid']);
            $this->assertSame(1, $p['invalid']);
            $report = $s->import($p, 'skip', $u->id, 'test.csv');
            $this->assertSame(1, $report['skipped']);
            $this->assertCount(1, $report['errors']);
            $this->assertNotSame('NEW-CSV-SERIAL', $first->fresh()->serial_number);
            $report = $s->import($p, 'update', $u->id, 'test.csv');
            $this->assertSame(1, $report['updated']);
            $this->assertSame('NEW-CSV-SERIAL', $first->fresh()->serial_number);
        } finally {
            unlink($path);
        }
    }
}

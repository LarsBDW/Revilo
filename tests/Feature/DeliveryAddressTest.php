<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductModel;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Support\Pricing;
use Database\Seeders\ShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['revilo.test_checkout' => true]);
        $this->seed(ShopSeeder::class);
    }

    private function address(array $changes = []): array
    {
        return array_merge(['label' => 'Home in Germany', 'street' => 'Teststraße', 'house_number' => '12', 'postal_code' => '01067', 'city' => 'Dresden', 'country' => 'DE'], $changes);
    }

    private function saveAddress(User $user, array $changes = []): int
    {
        return DB::table('customer_addresses')->insertGetId(['user_id' => $user->id, 'label' => $changes['label'] ?? 'Home in Germany', 'address' => json_encode($this->address($changes)), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cart(bool $aboveThreshold = false): void
    {
        $model = ProductModel::firstOrFail();
        $configuration = app(CatalogService::class)->defaultConfiguration($model);
        if ($aboveThreshold) {
            $configuration = array_merge($configuration, ['ram_gb' => 32, 'ssd_gb' => 1024, 'warranty_months' => 24]);
        }
        app(CartService::class)->add($model, $configuration, 1);
    }

    private function orderInput(array $changes = []): array
    {
        return array_merge($this->address(), ['name' => 'Test Customer', 'email' => 'address-test@example.test', 'terms' => 1, 'test_acknowledgement' => 1, 'idempotency_key' => session('checkout_key')], $changes);
    }

    public function test_german_address_can_be_saved_with_a_leading_zero_postcode(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/de/account/addresses', $this->address())->assertSessionHasNoErrors();
        $address = json_decode(DB::table('customer_addresses')->where('user_id', $user->id)->first()->address, true);
        $this->assertSame('01067', $address['postal_code']);
        $this->assertSame('DE', $address['country']);
        $this->get('/de/account')->assertOk()->assertSee('Deutschland')->assertSee('01067');
        $this->get('/de/shipping')->assertOk()->assertSee('Deutschland: € 9,95')->assertSee('Deutsch');
    }

    public function test_saved_addresses_are_offered_only_to_their_owner_and_selected_explicitly(): void
    {
        $user = User::factory()->create();
        $id = $this->saveAddress($user);
        $this->saveAddress(User::factory()->create(), ['street' => 'Another customers private street']);
        $this->actingAs($user);
        $this->cart();
        $this->get('/en/checkout')->assertOk()->assertSee('Would you like to use a saved address?')->assertSee('Use this address')->assertSee('Home in Germany')->assertDontSee('Another customers private street')->assertSee('name="street" value=""', false);
        $this->post('/en/checkout/address', ['address_id' => $id, 'name' => 'Delivery Recipient', 'email' => $user->email])->assertRedirect('/en/checkout');
        $this->get('/en/checkout')->assertOk()->assertSee('name="street" value="Teststraße"', false)->assertSee('name="postal_code" value="01067"', false)->assertSee('name="name" value="Delivery Recipient"', false)->assertViewHas('country', 'DE')->assertViewHas('totals', fn ($totals) => $totals['shipping_cents'] === 995);
        $this->post('/en/checkout/address', ['address_id' => 0, 'name' => 'Delivery Recipient', 'email' => $user->email])->assertRedirect('/en/checkout');
        $this->get('/en/checkout')->assertOk()->assertSee('name="street" value=""', false)->assertSee('name="name" value="Delivery Recipient"', false)->assertViewHas('selectedAddress', null);
    }

    public function test_foreign_saved_address_cannot_be_selected_or_submitted_at_checkout(): void
    {
        $owner = User::factory()->create();
        $foreignId = $this->saveAddress($owner);
        $this->actingAs(User::factory()->create());
        $this->cart();
        $this->get('/en/checkout')->assertOk()->assertDontSee('Home in Germany');
        $this->post('/en/checkout/address', ['address_id' => $foreignId])->assertForbidden();
        $this->post('/en/checkout', $this->orderInput(['selected_address_id' => $foreignId]))->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_guest_can_order_to_germany_without_access_to_saved_addresses(): void
    {
        $this->saveAddress(User::factory()->create());
        $this->cart();
        $this->get('/de/cart?country=DE')->assertOk()->assertViewHas('totals', fn ($totals) => $totals['shipping_cents'] === 995)->assertSee('/de/checkout?country=DE', false);
        $this->get('/de/checkout?country=DE')->assertOk()->assertViewHas('country', 'DE')->assertDontSee('Home in Germany');
        $this->post('/de/checkout', $this->orderInput())->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('DE', $order->shipping_address['country']);
        $this->assertSame('01067', $order->shipping_address['postal_code']);
        $this->assertSame(995, $order->shipping_cents);
        $this->assertSame('de', $order->locale);
        $this->assertTrue($order->is_test);
        $this->assertNull($order->paid_at);
        $this->post('/de/checkout/address', ['address_id' => 1])->assertRedirect('/de/login');
    }

    public function test_saved_address_can_be_edited_for_one_order_without_changing_the_account(): void
    {
        $user = User::factory()->create();
        $id = $this->saveAddress($user);
        $this->actingAs($user);
        $this->cart();
        $this->get('/en/checkout')->assertOk();
        $this->post('/en/checkout', $this->orderInput(['selected_address_id' => $id, 'street' => 'Temporary delivery street', 'postal_code' => '1000', 'city' => 'Brussels', 'country' => 'BE']))->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('Temporary delivery street', $order->shipping_address['street']);
        $saved = json_decode(DB::table('customer_addresses')->find($id)->address, true);
        $this->assertSame('Teststraße', $saved['street']);
        $this->assertSame('DE', $saved['country']);
    }

    public function test_invalid_german_postcodes_are_rejected_for_saved_addresses_and_checkout(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['1067', '0106A', '123456'] as $postcode) {
            $this->post('/de/account/addresses', $this->address(['postal_code' => $postcode]))->assertSessionHasErrors('postal_code');
        }
        $this->assertDatabaseCount('customer_addresses', 0);
        $this->cart();
        $this->get('/de/checkout')->assertOk();
        $this->post('/de/checkout', $this->orderInput(['postal_code' => '1067']))->assertSessionHasErrors('postal_code');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_free_shipping_threshold_applies_to_germany(): void
    {
        $this->cart(true);
        $this->get('/de/checkout?country=DE')->assertOk()->assertViewHas('totals', fn ($totals) => $totals['subtotal_cents'] >= 25000 && $totals['shipping_cents'] === 0);
        $this->post('/de/checkout', $this->orderInput())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, Order::firstOrFail()->shipping_cents);
    }

    public function test_german_delivery_fee_is_editable_and_changed_prices_require_review(): void
    {
        $this->cart();
        $this->get('/de/checkout?country=DE')->assertOk();
        Setting::updateOrCreate(['key' => 'shipping_de_cents'], ['value' => ['cents' => 1295]]);
        app()->forgetInstance(Pricing::class);
        $this->post('/de/checkout', $this->orderInput())->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->get('/de/checkout?country=DE')->assertOk()->assertViewHas('totals', fn ($totals) => $totals['shipping_cents'] === 1295);
        $this->post('/de/checkout', $this->orderInput())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1295, Order::firstOrFail()->shipping_cents);
    }
}

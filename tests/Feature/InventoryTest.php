<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\ProductModel;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function product(): ProductModel
    {
        return ProductModel::create(['slug' => 'test-laptop', 'brand' => 'Dell', 'name' => 'Latitude 5490', 'description' => ['nl' => 'Test'], 'category' => 'business', 'images' => [], 'supported_ram' => [8, 16, 32], 'supported_ssd' => [256, 512, 1024], 'operating_systems' => ['Windows 11 Pro', 'Linux', 'No OS'], 'specifications' => [], 'published' => true]);
    }

    private function unit(ProductModel $p, array $attributes = []): InventoryUnit
    {
        return InventoryUnit::create(array_merge(['product_model_id' => $p->id, 'inventory_id' => 'TEST-'.random_int(10000, 99999), 'processor' => 'Intel Core i5-8350U', 'processor_family' => 'i5', 'processor_generation' => 8, 'ram_gb' => 8, 'ssd_gb' => 256, 'screen_inches' => 14, 'resolution' => 'Full HD', 'keyboard_layout' => 'QWERTY', 'grade' => 'A', 'purchase_cents' => 4000, 'selling_cents' => 13900, 'status' => 'available'], $attributes));
    }

    private function config(): array
    {
        return ['ram_gb' => 16, 'ssd_gb' => 512, 'grade' => 'A', 'processor' => 'Intel Core i5-8350U', 'operating_system' => 'Windows 11 Pro'];
    }

    private function parts(ProductModel $p, int $quantity = 1): void
    {
        foreach (['ram' => [16, 2500, 1200], 'ssd' => [512, 2000, 1000]] as $type => [$capacity,$price,$cost]) {
            Component::create(['sku' => $type, 'name' => $type, 'type' => $type, 'capacity' => $capacity, 'compatible_models' => [$p->id], 'quantity' => $quantity, 'cost_cents' => $cost, 'upgrade_cents' => $price]);
        }
    }

    private function order(): Order
    {
        return Order::create(['public_id' => fake()->uuid(), 'number' => 'TEST-'.random_int(100000, 999999), 'idempotency_key' => fake()->uuid(), 'email' => 'test@example.test', 'name' => 'Test', 'shipping_address' => [], 'subtotal_cents' => 18400, 'total_cents' => 18400, 'reservation_expires_at' => now()->addMinutes(30)]);
    }

    public function test_real_upgrade_components_determine_price_and_quantity(): void
    {
        $p = $this->product();
        $this->unit($p);
        $this->unit($p);
        $this->parts($p);
        $q = app(CatalogService::class)->quote($p, $this->config());
        $this->assertSame(18400, $q['price_cents']);
        $this->assertSame(1, $q['stock']);
    }

    public function test_missing_or_incompatible_parts_cannot_be_ordered(): void
    {
        $p = $this->product();
        $this->unit($p);
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->quote($p, $this->config());
    }

    public function test_nonexistent_grade_is_unavailable(): void
    {
        $p = $this->product();
        $this->unit($p);
        $this->parts($p);
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->quote($p, array_merge($this->config(), ['grade' => 'B']));
    }

    public function test_unsupported_ram_is_rejected(): void
    {
        $p = $this->product();
        $this->unit($p);
        $this->expectException(ValidationException::class);
        app(CatalogService::class)->quote($p, array_merge($this->config(), ['ram_gb' => 64]));
    }

    public function test_reservation_blocks_second_buyer_and_releases_components(): void
    {
        $p = $this->product();
        $u = $this->unit($p);
        $this->parts($p);
        $o = $this->order();
        $s = app(InventoryService::class);
        DB::transaction(function () use ($s, $p, $o) {
            $plans = $s->allocate([['product_model_id' => $p->id, 'configuration' => $this->config(), 'quantity' => 1]]);
            $s->reserve($o, $plans);
        });
        $this->assertSame('reserved', $u->fresh()->status);
        $this->assertSame(1, Component::first()->reserved);
        try {
            app(CatalogService::class)->quote($p, $this->config());
            $this->fail('Reserved unit was purchasable');
        } catch (ValidationException) {
        }$s->release($o);
        $s->release($o);
        $this->assertSame('available', $u->fresh()->status);
        $this->assertSame(0, Component::first()->reserved);
    }

    public function test_atomic_allocation_rolls_back_if_quantity_exceeds_stock(): void
    {
        $p = $this->product();
        $u = $this->unit($p);
        $this->parts($p);
        $o = $this->order();
        $s = app(InventoryService::class);
        try {
            DB::transaction(function () use ($s, $p, $o) {
                $s->reserve($o, $s->allocate([['product_model_id' => $p->id, 'configuration' => $this->config(), 'quantity' => 2]]));
            });
            $this->fail('Oversold');
        } catch (ValidationException) {
        }$this->assertSame('available', $u->fresh()->status);
        $this->assertSame(0, Component::first()->reserved);
    }

    public function test_availability_filters_use_fulfillable_upgrades(): void
    {
        $p = $this->product();
        $this->unit($p);
        $this->assertCount(0, app(CatalogService::class)->matching(['ram' => [32]]));
        $this->parts($p);
        $this->assertCount(1, app(CatalogService::class)->matching(['ram' => [16], 'ssd' => [512]]));
    }

    public function test_costs_and_serial_numbers_are_private(): void
    {
        $p = $this->product();
        $u = $this->unit($p, ['serial_number' => 'PRIVATE-123']);
        $this->assertArrayNotHasKey('serial_number', $u->toArray());
        $this->assertArrayNotHasKey('purchase_cents', $u->toArray());
        $this->assertNotSame('PRIVATE-123', $u->getRawOriginal('serial_number'));
    }

    public function test_money_is_parsed_without_float_rounding(): void
    {
        $this->assertSame(123456, Money::parse('€ 1.234,56'));
        $this->assertSame(13900, Money::parse('139'));
        $this->assertSame(2586, Money::vat(14900, 'standard'));
        $this->assertSame(1892, Money::vat(14900, 'margin', 4000));
    }
}

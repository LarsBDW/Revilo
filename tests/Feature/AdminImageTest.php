<?php

namespace Tests\Feature;

use App\Models\InventoryUnit;
use App\Models\Order;
use App\Models\ProductModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => 'admin'])->save();
    }

    private function product(array $images = []): ProductModel
    {
        return ProductModel::create([
            'slug' => 'test-'.Str::random(10), 'brand' => 'Dell', 'name' => 'Latitude',
            'description' => ['nl' => 'Test'], 'category' => 'business', 'images' => $images,
            'supported_ram' => [8, 16], 'supported_ssd' => [256, 512],
            'operating_systems' => ['Windows 11 Pro'], 'specifications' => [], 'published' => true,
        ]);
    }

    private function unit(ProductModel $product, array $images = []): InventoryUnit
    {
        return InventoryUnit::create([
            'product_model_id' => $product->id, 'inventory_id' => 'TEST-'.Str::random(10),
            'processor' => 'Intel Core i5-8350U', 'processor_family' => 'i5', 'processor_generation' => 8,
            'ram_gb' => 8, 'ssd_gb' => 256, 'screen_inches' => 14, 'resolution' => 'Full HD',
            'keyboard_layout' => 'QWERTY', 'grade' => 'A', 'purchase_cents' => 4000,
            'selling_cents' => 13900, 'status' => 'available', 'images' => $images,
        ]);
    }

    public function test_product_and_inventory_editors_offer_removal_for_each_photo(): void
    {
        $images = ['/assets/products/first.svg', '/assets/products/second.svg'];
        $product = $this->product($images);
        $unit = $this->unit($product, $images);

        foreach (['products' => $product, 'inventory' => $unit] as $resource => $record) {
            $response = $this->actingAs($this->admin)->get("/admin/{$resource}/{$record->id}/edit");
            $response->assertOk()->assertSee('Verwijderen')->assertSee('Deze afbeelding verwijderen?')
                ->assertSee('Afbeelding 2 verwijderen')->assertSee('name="_method" value="DELETE"', false)
                ->assertSee('name="_token"', false)
                ->assertSee(route('admin.image.destroy', ['resource' => $resource, 'id' => $record->id]), false);
            $this->assertSame(2, substr_count($response->getContent(), 'class="admin-photo"'));
        }
    }

    public function test_uploaded_photos_can_be_removed_without_changing_other_images(): void
    {
        $original = '/assets/products/original.svg';
        $product = $this->product([$original]);
        $unit = $this->unit($product, [$original]);
        $disk = Storage::disk('public');

        foreach (['products' => $product, 'inventory' => $unit] as $resource => $record) {
            $editor = "/admin/{$resource}/{$record->id}/edit";
            $endpoint = "/admin/{$resource}/{$record->id}/image";
            $photo = UploadedFile::fake()->createWithContent('laptop.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
            $this->actingAs($this->admin)->post($endpoint, ['image' => $photo])
                ->assertRedirect($editor)->assertSessionHas('success', 'Foto toegevoegd');
            $images = $record->fresh()->images;
            $this->assertCount(2, $images);
            $this->assertSame($original, $images[0]);
            $path = $disk->allFiles('photos')[0];
            $disk->assertExists($path);

            $this->delete($endpoint, ['image' => $images[1]])->assertRedirect($editor)
                ->assertSessionHas('success', 'Foto verwijderd');
            $this->assertSame([$original], $record->fresh()->images);
            $disk->assertMissing($path);
            $this->assertDatabaseHas('admin_audit_logs', [
                'action' => 'image.remove', 'entity' => $record->getTable(), 'entity_id' => $record->id,
                'user_id' => $this->admin->id,
            ]);
        }
    }

    public function test_removing_first_and_last_images_updates_the_main_image_and_placeholder(): void
    {
        $first = '/assets/products/placeholder.svg';
        $second = '/assets/products/second.svg';
        $product = $this->product([$first, $second]);
        $endpoint = "/admin/products/{$product->id}/image";

        $this->actingAs($this->admin)->delete($endpoint, ['image' => $first])->assertSessionHasNoErrors();
        $this->assertSame([$second], $product->fresh()->images);
        $this->assertSame($second, $product->fresh()->image());
        $this->assertFileExists(public_path($first));

        $this->delete($endpoint, ['image' => $second])->assertSessionHasNoErrors();
        $this->assertSame([], $product->fresh()->images);
        $this->assertSame('/assets/products/placeholder.svg', $product->fresh()->image());
        $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee('Nog geen afbeeldingen.');
    }

    public function test_shared_upload_is_kept_until_the_last_product_or_unit_removes_it(): void
    {
        $path = 'photos/shared.png';
        $disk = Storage::disk('public');
        $disk->put($path, 'shared image');
        $absolute = $disk->url($path);
        $relative = '/storage/'.$path;
        $product = $this->product([$absolute]);
        $otherProduct = $this->product([$relative]);
        $unit = $this->unit($product, [$relative]);

        $this->actingAs($this->admin)->delete("/admin/products/{$product->id}/image", ['image' => $absolute])
            ->assertSessionHasNoErrors();
        $disk->assertExists($path);
        $this->assertSame([$relative], $unit->fresh()->images);
        $this->delete("/admin/products/{$otherProduct->id}/image", ['image' => $relative])->assertSessionHasNoErrors();
        $disk->assertExists($path);
        $this->delete("/admin/inventory/{$unit->id}/image", ['image' => $relative])->assertSessionHasNoErrors();
        $disk->assertMissing($path);
    }

    public function test_past_order_images_remain_available_after_removal_from_the_product(): void
    {
        $path = 'photos/order.png';
        $disk = Storage::disk('public');
        $disk->put($path, 'order photo');
        $image = $disk->url($path);
        $product = $this->product([$image]);
        $unit = $this->unit($product);
        $order = Order::create([
            'public_id' => Str::uuid(), 'number' => 'TEST-ORDER', 'idempotency_key' => Str::uuid(),
            'email' => 'buyer@example.test', 'name' => 'Test Buyer', 'shipping_address' => [],
            'subtotal_cents' => 13900, 'total_cents' => 13900,
        ]);
        $item = $order->items()->create([
            'inventory_unit_id' => $unit->id, 'product_model_id' => $product->id,
            'snapshot' => ['name' => 'Dell Latitude', 'image' => '/storage/'.$path], 'components' => [],
            'price_cents' => 13900, 'purchase_cents' => 4000, 'refurbishment_cents' => 0,
        ]);

        $this->actingAs($this->admin)->delete("/admin/products/{$product->id}/image", ['image' => $image])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $product->fresh()->images);
        $this->assertSame('/storage/'.$path, $item->fresh()->snapshot['image']);
        $disk->assertExists($path);
    }

    public function test_guests_and_customers_cannot_remove_admin_images(): void
    {
        $image = '/storage/photos/private.png';
        Storage::disk('public')->put('photos/private.png', 'photo');
        $product = $this->product([$image]);
        $unit = $this->unit($product, [$image]);
        $customer = User::factory()->create();

        foreach (['products' => $product, 'inventory' => $unit] as $resource => $record) {
            auth()->forgetGuards();
            $endpoint = "/admin/{$resource}/{$record->id}/image";
            $this->delete($endpoint, ['image' => $image])->assertRedirect('/nl/login');
            $this->actingAs($customer)->delete($endpoint, ['image' => $image])->assertForbidden();
            $this->assertSame([$image], $record->fresh()->images);
        }
        Storage::disk('public')->assertExists('photos/private.png');
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'image.remove']);
    }

    public function test_other_product_images_and_stale_removal_requests_are_rejected(): void
    {
        $first = '/storage/photos/first.png';
        $second = '/storage/photos/second.png';
        Storage::disk('public')->put('photos/first.png', 'first');
        Storage::disk('public')->put('photos/second.png', 'second');
        $product = $this->product([$first, $second]);
        $other = $this->product(['/storage/photos/other.png']);
        $endpoint = "/admin/products/{$product->id}/image";

        $this->actingAs($this->admin)->delete($endpoint, ['image' => $other->image()])->assertSessionHasErrors('image');
        $this->assertSame([$first, $second], $product->fresh()->images);
        $this->delete($endpoint, ['image' => $first])->assertRedirect();
        $this->delete($endpoint, ['image' => $first])->assertSessionHasErrors('image');
        $this->assertSame([$second], $product->fresh()->images);
        Storage::disk('public')->assertExists('photos/second.png');
    }

    public function test_external_urls_and_traversal_paths_cannot_delete_local_files(): void
    {
        $disk = Storage::disk('public');
        $disk->put('photos/keep.png', 'keep');
        $disk->put('keep.png', 'also keep');
        $images = ['https://other.example/storage/photos/keep.png', '/storage/photos/../keep.png'];
        $product = $this->product($images);

        foreach ($images as $image) {
            $this->actingAs($this->admin)->delete("/admin/products/{$product->id}/image", ['image' => $image])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame([], $product->fresh()->images);
        $disk->assertExists(['photos/keep.png', 'keep.png']);
    }
}

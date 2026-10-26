<?php

namespace Tests\Feature;

use App\Models\ProductModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeding_requires_preview_mode_and_an_empty_catalogue(): void
    {
        config(['revilo.public_preview' => false]);
        $this->artisan('revilo:seed-preview')->assertFailed();
        $this->assertSame(0, ProductModel::count());
        config(['revilo.public_preview' => true]);
        $this->artisan('revilo:seed-preview')->assertSuccessful();
        $this->assertSame(6, ProductModel::count());
        $this->artisan('revilo:seed-preview')->assertFailed();
        $this->assertSame(6, ProductModel::count());
    }
}

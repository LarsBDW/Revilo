<?php

namespace Tests\Feature;

use App\Models\ProductModel;
use App\Services\CatalogService;
use App\Support\Pricing;
use Database\Seeders\ShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(ShopSeeder::class);
    }

    public function test_option_prices_match_real_quotes_and_unavailable_choices_have_no_price(): void
    {
        $product = ProductModel::first();
        $product->update(['supported_ram' => [8, 16, 32, 64]]);
        $catalog = app(CatalogService::class);
        $configuration = $catalog->defaultConfiguration($product);
        $options = $catalog->optionPrices($product, $configuration);

        foreach ($options as $field => $values) {
            foreach ($values as $value => $details) {
                if ($details['available']) {
                    $quote = $catalog->quote($product, array_merge($configuration, [$field => $value]));
                    $this->assertSame($quote['price_cents'], $details['price_cents']);
                    $this->assertGreaterThanOrEqual(0, $details['extra_cents']);
                } else {
                    $this->assertNull($details['price_cents']);
                }
            }
        }
        $this->assertFalse($options['ram_gb'][64]['available']);
        $this->assertNull($options['ram_gb'][64]['extra_cents']);
        $this->getJson('/nl/laptops/'.$product->slug.'/quote?'.http_build_query($configuration))
            ->assertOk()->assertJsonPath('option_prices.ram_gb.64.available', false)
            ->assertJsonPath('option_prices.warranty_months.24.extra_cents', Pricing::warranty());
    }

    public function test_product_cards_render_in_all_languages_and_selection_preserves_real_configuration(): void
    {
        $product = ProductModel::first();
        $configuration = app(CatalogService::class)->defaultConfiguration($product);
        $configuration['ram_gb'] = 32;
        $configuration['ssd_gb'] = 1024;
        $configuration['warranty_months'] = 24;

        foreach (['nl', 'en', 'de'] as $locale) {
            $this->get('/'.$locale.'/laptops/'.$product->slug.'?'.http_build_query($configuration))
                ->assertOk()->assertSee('32 GB RAM')->assertSee('1 TB SSD')
                ->assertSee('class="config-choice"', false)->assertSee('class="config-extra"', false)
                ->assertSee('name="ram_gb" value="32" checked', false)
                ->assertSee('name="ssd_gb" value="1024" checked', false)
                ->assertSee('name="warranty_months" value="24" checked', false);
        }
    }
}

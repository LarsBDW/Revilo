<?php

namespace App\Console\Commands;

use App\Models\ProductModel;
use Illuminate\Console\Command;

class SeedPreview extends Command
{
    protected $signature = 'revilo:seed-preview';

    protected $description = 'Populate an empty public preview with clearly labelled demonstration inventory';

    public function handle(): int
    {
        if (! config('revilo.public_preview')) {
            $this->error('Demo seeding requires PUBLIC_PREVIEW_ENABLED=true.');

            return self::FAILURE;
        }
        if (ProductModel::exists()) {
            $this->error('The catalogue already contains products; no demonstration data was added.');

            return self::FAILURE;
        }

        return $this->call('db:seed', ['--class' => 'Database\\Seeders\\ShopSeeder', '--force' => true]);
    }
}

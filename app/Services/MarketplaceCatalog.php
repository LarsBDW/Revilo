<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\MarketplaceDraft;
use App\Models\MarketplacePhoto;
use App\Models\ProductModel;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceCatalog
{
    public function attach(InventoryUnit $unit): MarketplaceDraft
    {
        return MarketplaceDraft::firstOrCreate(['inventory_unit_id' => $unit->id], ['data' => ['channels' => ['ebay']], 'version' => 1]);
    }

    public function token(InventoryUnit $unit): string
    {
        return hash('sha256', json_encode([$unit->getRawOriginal(), $unit->model->getRawOriginal()]));
    }

    public function photo(string $image): ?MarketplacePhoto
    {
        $path = parse_url($image, PHP_URL_PATH) ?: '';
        if (preg_match('~^/marketplace-photos/([a-f0-9]{48}\.(?:jpg|png|webp))$~', $path, $match)) {
            return MarketplacePhoto::where('file', $match[1])->first();
        }
        $host = parse_url($image, PHP_URL_HOST);
        if ($host && $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
            return null;
        }
        if (! preg_match('~^/(storage|assets)/([a-zA-Z0-9_./-]+\.(jpg|jpeg|png|webp))$~i', $path, $match) || str_contains($path, '..')) {
            return null;
        }
        return MarketplacePhoto::firstOrCreate(['disk' => $match[1] === 'storage' ? 'public' : 'assets', 'path' => $match[1] === 'storage' ? $match[2] : 'assets/'.$match[2]], ['file' => bin2hex(random_bytes(24)).'.'.strtolower($match[3]), 'name' => basename($path)]);
    }

    public function serialize(MarketplaceDraft $draft): array
    {
        $unit = $draft->unit()->with('model')->firstOrFail();
        $model = $unit->model;
        $discount = min(80, (int) $model->discount_percent);
        $data = $draft->data ?? [];
        $photos = collect($unit->images ?: $model->images ?: [])->map(fn ($image) => $this->photo($image))->filter()->map(fn ($p) => ['file' => $p->file, 'name' => $p->name])->values()->all();
        return array_merge([
            'channels' => ['ebay'], 'mpCategoryId' => '', 'belgiumCategoryId' => '', 'ebayCategoryId' => '',
            'mpAttributes' => (object) [], 'belgiumAttributes' => (object) [], 'ebayAspects' => (object) [],
            'titleFr' => '', 'descriptionFr' => '',
        ], $data, [
            'id' => $draft->id, 'version' => $draft->version, 'catalogToken' => $this->token($unit),
            'inventoryUnitId' => $unit->id, 'inventoryId' => $unit->inventory_id,
            'sku' => 'REVILO-UNIT-'.$unit->id,
            'title' => $data['title'] ?? Str::limit($model->brand.' '.$model->name, 60, ''),
            'description' => $model->description['nl'] ?? '',
            'priceCents' => $unit->selling_cents - intdiv($unit->selling_cents * $discount, 100),
            'condition' => $data['condition'] ?? match ($unit->grade) {'A' => 'USED_EXCELLENT', 'B' => 'USED_GOOD', default => 'USED_ACCEPTABLE'},
            'conditionDescription' => $unit->defects ?? '', 'notes' => $unit->internal_notes ?? '',
            'stockStatus' => $unit->status === 'sold' ? 'sold' : 'available', 'inventoryStatus' => $unit->status,
            'photos' => $photos, 'brand' => $model->brand, 'modelName' => $model->name,
            'websiteCategory' => $model->category, 'websitePublished' => $model->published,
            'processor' => $unit->processor, 'processorFamily' => $unit->processor_family,
            'processorGeneration' => $unit->processor_generation, 'ramGb' => $unit->ram_gb,
            'ssdGb' => $unit->ssd_gb, 'screenInches' => $unit->screen_inches,
            'resolution' => $unit->resolution, 'graphics' => $unit->graphics,
            'keyboardLayout' => $unit->keyboard_layout, 'operatingSystem' => $unit->operating_system,
            'grade' => $unit->grade, 'webshopUrl' => route('products.show', ['locale' => 'nl', 'product' => $model]),
            'updatedAt' => max($draft->updated_at, $unit->updated_at, $model->updated_at)->toIso8601String(),
            'listings' => $draft->listings()->get()->map(fn ($l) => array_merge($l->data, ['channel' => $l->channel]))->all(),
        ]);
    }

    public function save(array $input): MarketplaceDraft
    {
        return DB::transaction(function () use ($input) {
            $draft = isset($input['id']) ? MarketplaceDraft::whereKey($input['id'])->lockForUpdate()->firstOrFail() : null;
            $unit = $draft ? InventoryUnit::whereKey($draft->inventory_unit_id)->lockForUpdate()->with('model')->firstOrFail() : null;
            if ($draft && ($draft->version !== (int) $input['version'] || ! hash_equals($this->token($unit), $input['catalogToken'] ?? ''))) {
                throw ValidationException::withMessages(['product' => 'De laptop is elders gewijzigd. Open hem opnieuw.']);
            }
            if ($draft && $draft->actions()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['product' => 'Wacht tot de lopende kanaalactie klaar is.']);
            }
            if ($unit && in_array($unit->status, ['reserved', 'sold'], true)) {
                throw ValidationException::withMessages(['product' => 'Gereserveerde en verkochte laptops wijzig je via de bestel- of retourworkflow.']);
            }
            $photos = MarketplacePhoto::whereIn('file', array_column($input['photos'], 'file'))->get()->keyBy('file');
            if ($photos->count() !== count($input['photos'])) {
                throw ValidationException::withMessages(['photos' => 'Een foto ontbreekt. Upload hem opnieuw.']);
            }
            $images = array_map(fn ($p) => '/marketplace-photos/'.$p['file'], $input['photos']);
            if ($input['websitePublished'] && (empty($images) || $input['priceCents'] <= 0 || ! $input['condition'])) {
                throw ValidationException::withMessages(['product' => 'Voor publicatie op Revilo zijn een echte foto, prijs en bevestigde conditie nodig.']);
            }
            $model = $unit?->model;
            if ($model && $model->units()->count() > 1) {
                // Other physical units retain their shared product and existing URL.
                $model = $model->replicate();
                $model->slug = Str::slug($input['brand'].' '.$input['modelName']).'-'.Str::lower(Str::random(10));
            }
            if (! $model) {
                $model = new ProductModel(['slug' => Str::slug($input['brand'].' '.$input['modelName']).'-'.Str::lower(Str::random(10)), 'discount_percent' => 0, 'featured' => false, 'sold_count' => 0]);
            }
            $description = $model->description ?? [];
            $description['nl'] = $input['description'];
            $model->fill([
                'brand' => $input['brand'], 'name' => $input['modelName'], 'category' => $input['websiteCategory'],
                'description' => $description, 'images' => $images,
                'supported_ram' => array_values(array_unique([...($model->supported_ram ?? []), (int) $input['ramGb']])),
                'supported_ssd' => array_values(array_unique([...($model->supported_ssd ?? []), (int) $input['ssdGb']])),
                'operating_systems' => array_values(array_unique([...($model->operating_systems ?? []), $input['operatingSystem']])),
                'specifications' => $model->specifications ?? [], 'published' => $input['websitePublished'],
            ])->save();
            // Retain a pre-existing model discount while keeping the displayed price equal to the entered price.
            $basePrice = intdiv($input['priceCents'] * 100, 100 - min(80, (int) $model->discount_percent));
            $fresh = ! $unit;
            $unit ??= new InventoryUnit(['inventory_id' => 'REV-'.Str::upper(Str::random(12)), 'purchase_cents' => 0, 'refurbishment_cents' => 0, 'vat_treatment' => 'standard']);
            $unit->fill([
                'product_model_id' => $model->id, 'processor' => $input['processor'], 'processor_family' => $input['processorFamily'],
                'processor_generation' => $input['processorGeneration'], 'ram_gb' => $input['ramGb'], 'ssd_gb' => $input['ssdGb'],
                'screen_inches' => $input['screenInches'], 'resolution' => $input['resolution'], 'graphics' => $input['graphics'],
                'keyboard_layout' => $input['keyboardLayout'], 'operating_system' => $input['operatingSystem'],
                'grade' => $input['grade'], 'selling_cents' => $basePrice, 'images' => $images,
                'defects' => $input['conditionDescription'] ?? null, 'internal_notes' => $input['notes'] ?? null,
            ]);
            if ($fresh) {
                $unit->status = $input['websitePublished'] ? 'available' : 'awaiting_inspection';
            } elseif ($unit->status === 'awaiting_inspection' && $input['websitePublished']) {
                $unit->status = 'available';
            }
            $unit->save();
            $draft ??= new MarketplaceDraft(['inventory_unit_id' => $unit->id, 'version' => 0]);
            $draft->data = collect($input)->only(['title', 'titleFr', 'descriptionFr', 'condition', 'channels', 'mpCategoryId', 'belgiumCategoryId', 'ebayCategoryId', 'mpAttributes', 'belgiumAttributes', 'ebayAspects'])->all();
            $draft->version++;
            $draft->save();
            DB::table('admin_audit_logs')->insert(['user_id' => auth()->id(), 'action' => 'marketplace.catalog.save', 'entity' => 'inventory_units', 'entity_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);
            return $draft;
        }, 3);
    }
}

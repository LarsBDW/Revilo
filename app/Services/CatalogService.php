<?php

namespace App\Services;

use App\Models\Component;
use App\Models\InventoryUnit;
use App\Models\ProductModel;
use App\Support\Pricing;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    private ?Collection $components = null;

    public function components(): Collection
    {
        return $this->components ??= Component::orderBy('id')->get();
    }

    public function configuration(ProductModel $model, array $input): array
    {
        $c = [
            'ram_gb' => (int) ($input['ram_gb'] ?? 0), 'ssd_gb' => (int) ($input['ssd_gb'] ?? 0),
            'grade' => (string) ($input['grade'] ?? ''), 'processor' => (string) ($input['processor'] ?? ''),
            'keyboard_layout' => (string) ($input['keyboard_layout'] ?? 'QWERTY'),
            'operating_system' => (string) ($input['operating_system'] ?? 'Windows 11 Pro'),
            'battery' => (string) ($input['battery'] ?? 'standard'), 'charger' => (string) ($input['charger'] ?? 'included'),
            'warranty_months' => (int) ($input['warranty_months'] ?? 12),
        ];
        if (! in_array($c['ram_gb'], $model->supported_ram, true) || ! in_array($c['ssd_gb'], $model->supported_ssd, true) ||
            ! in_array($c['operating_system'], $model->operating_systems, true) || ! in_array($c['grade'], ['A', 'B', 'C'], true) ||
            ! in_array($c['battery'], ['standard', 'new'], true) || ! in_array($c['charger'], ['included', 'extra'], true) ||
            ! in_array($c['warranty_months'], [12, 24], true) || ! in_array($c['keyboard_layout'], ['QWERTY', 'AZERTY'], true)) {
            throw ValidationException::withMessages(['configuration' => __('shop.invalid_configuration')]);
        }

        return $c;
    }

    /** Calculates a real, fulfillable plan for one physical unit; browser prices are never used. */
    public function candidate(InventoryUnit $unit, array $c, Collection $components, array $remaining = []): ?array
    {
        if ($unit->status !== 'available' || $unit->grade !== $c['grade'] || $unit->keyboard_layout !== $c['keyboard_layout'] ||
            ($c['processor'] !== '' && $unit->processor !== $c['processor'])) {
            return null;
        }
        $used = [];
        $cost = 0;
        $upgrade = 0;
        $breakdown = [];
        foreach (['ram' => $c['ram_gb'], 'ssd' => $c['ssd_gb'], 'battery' => $c['battery'] === 'new' ? 1 : 0, 'charger' => $c['charger'] === 'extra' ? 1 : 0] as $type => $capacity) {
            if ($type === 'ram' && $capacity === $unit->ram_gb || $type === 'ssd' && $capacity === $unit->ssd_gb || $capacity === 0) {
                continue;
            }
            // Do not downgrade installed hardware and still charge a replacement upgrade.
            if ($type === 'ram' && $capacity < $unit->ram_gb || $type === 'ssd' && $capacity < $unit->ssd_gb) {
                return null;
            }
            $component = $components->filter(fn ($part) => $part->type === $type && $part->capacity === $capacity && $part->compatible($unit->product_model_id) && ($remaining[$part->id] ?? $part->available()) > 0)->sortBy('upgrade_cents')->first();
            if (! $component) {
                return null;
            }
            $used[] = $component->id;
            $cost += $component->cost_cents;
            $upgrade += $component->upgrade_cents;
            $label = match ($type) {
                'ram' => $capacity.' GB '.__('shop.ram'),'ssd' => ($capacity === 1024 ? '1 TB' : $capacity.' GB').' '.__('shop.ssd'),'battery' => __('shop.new_battery'),'charger' => __('shop.extra_charger')
            };
            $breakdown[] = ['label' => $label, 'cents' => $component->upgrade_cents];
        }
        $model = $unit->model;
        $base = $unit->selling_cents;
        $discount = intdiv($base * $model->discount_percent, 100);
        $price = $base - $discount + $upgrade;
        if ($c['warranty_months'] === 24) {
            $amount = Pricing::warranty();
            $price += $amount;
            $breakdown[] = ['label' => __('shop.extended_warranty'), 'cents' => $amount];
        }

        return ['unit' => $unit, 'components' => $used, 'upgrade_cost_cents' => $cost, 'price_cents' => $price,
            'base_cents' => $base - $discount, 'original_cents' => $base + $upgrade + ($c['warranty_months'] === 24 ? Pricing::warranty() : 0), 'breakdown' => $breakdown];
    }

    public function plans(ProductModel $model, array $c, int $quantity = 1, ?Collection $units = null, ?Collection $components = null): array
    {
        $units ??= $model->units()->where('status', 'available')->with('model')->get();
        $components ??= $this->components();
        $remaining = $components->mapWithKeys(fn ($p) => [$p->id => $p->available()])->all();
        $plans = [];
        for ($i = 0; $i < $quantity; $i++) {
            $choices = $units->map(fn ($unit) => $this->candidate($unit, $c, $components, $remaining))->filter()->sortBy('price_cents');
            $plan = $choices->first();
            if (! $plan) {
                break;
            }
            $plans[] = $plan;
            $units = $units->reject(fn ($u) => $u->id === $plan['unit']->id);
            foreach ($plan['components'] as $id) {
                $remaining[$id]--;
            }
        }

        return $plans;
    }

    public function quote(ProductModel $model, array $input, int $quantity = 1): array
    {
        abort_unless($model->published, 404);
        $c = $this->configuration($model, $input);
        $plans = $this->plans($model, $c, min(20, max(1, $quantity)));
        if (count($plans) < $quantity) {
            throw ValidationException::withMessages(['configuration' => __('shop.unavailable_configuration')]);
        }
        $plan = $plans[0];
        $unit = $plan['unit'];

        return ['configuration' => $c, 'price_cents' => $plan['price_cents'], 'unit_prices' => array_column($plans, 'price_cents'), 'total_cents' => array_sum(array_column($plans, 'price_cents')),
            'original_cents' => $plan['original_cents'], 'breakdown' => $plan['breakdown'], 'base_cents' => $plan['base_cents'],
            'stock' => count($this->plans($model, $c, 20)), 'processor' => $unit->processor, 'battery_health' => $unit->battery_health,
            'defects' => $unit->defects, 'unit_images' => $unit->images ?? [], 'test_results' => $unit->tests()->get(['test', 'result', 'notes', 'tested_at'])->toArray(),
            'specifications' => ['processor' => $unit->processor, 'generation' => $unit->processor_generation, 'ram' => $c['ram_gb'].' GB', 'ssd' => $c['ssd_gb'].' GB', 'screen' => $unit->screen_inches.'″', 'resolution' => $unit->resolution, 'keyboard' => $unit->keyboard_layout, 'os' => $c['operating_system'], 'graphics' => $unit->graphics, 'touchscreen' => $unit->touchscreen ? __('pages.yes') : __('pages.no')],
            'units' => array_map(fn ($p) => ['battery_health' => $p['unit']->battery_health, 'grade' => $p['unit']->grade, 'defects' => $p['unit']->defects, 'images' => $p['unit']->images ?? [], 'accessories' => $p['unit']->accessories ?? []], $plans)];
    }

    public function availability(ProductModel $model, array $c): array
    {
        $units = $model->units()->where('status', 'available')->get();
        foreach ($units as $unit) {
            $unit->setRelation('model', $model);
        }
        $options = ['ram_gb' => $model->supported_ram, 'ssd_gb' => $model->supported_ssd, 'grade' => ['A', 'B', 'C'], 'processor' => $units->pluck('processor')->unique()->all(), 'keyboard_layout' => ['QWERTY', 'AZERTY'], 'operating_system' => $model->operating_systems, 'battery' => ['standard', 'new'], 'charger' => ['included', 'extra'], 'warranty_months' => [12, 24]];
        $result = [];
        foreach ($options as $field => $values) {
            foreach ($values as $value) {
                $selected = array_merge($c, [$field => $value]);
                $result[$field][(string) $value] = $units->contains(fn ($u) => $this->candidate($u, $selected, $this->components()) !== null);
            }
        }

        return $result;
    }

    public function defaultConfiguration(ProductModel $model): ?array
    {
        $unit = $model->units->where('status', 'available')->sortBy('selling_cents')->first();
        if (! $unit) {
            return null;
        }

        return ['ram_gb' => $unit->ram_gb, 'ssd_gb' => $unit->ssd_gb, 'grade' => $unit->grade, 'processor' => $unit->processor,
            'keyboard_layout' => $unit->keyboard_layout, 'operating_system' => $unit->operating_system, 'battery' => 'standard', 'charger' => 'included', 'warranty_months' => 12];
    }

    public function summary(ProductModel $model): array
    {
        $c = $this->defaultConfiguration($model);
        $units = $model->units->where('status', 'available');
        if (! $c) {
            return ['model' => $model, 'available' => false, 'price_cents' => 0, 'original_cents' => 0, 'unit' => null, 'configuration' => null, 'stock' => 0];
        }
        $unit = $units->sortBy('selling_cents')->first();
        $price = $unit->selling_cents - intdiv($unit->selling_cents * $model->discount_percent, 100);

        return ['model' => $model, 'available' => true, 'price_cents' => $price, 'original_cents' => $unit->selling_cents, 'unit' => $unit, 'configuration' => $c, 'stock' => $units->count()];
    }

    public function matching(array $filters): Collection
    {
        // Understand capacity searches such as "Dell 16GB RAM 512GB SSD".
        if (! empty($filters['q'])) {
            $term = (string) $filters['q'];
            $term = preg_replace_callback('/\b(\d{1,4})\s*(GB|TB)(?:\s*(RAM|SSD))?\b/i', function ($match) use (&$filters) {
                $size = (int) $match[1] * (strtoupper($match[2]) === 'TB' ? 1024 : 1);
                $kind = strtoupper($match[3] ?? '');
                $key = $kind === 'SSD' || $size > 128 ? 'ssd' : 'ram';
                $filters[$key] = [$size];

                return '';
            }, $term);
            $filters['q'] = trim(preg_replace('/\b(RAM|SSD|memory|storage|geheugen|opslag|speicher)\b/i', '', $term));
        }
        $query = ProductModel::where('published', true)->with(['units' => fn ($q) => $q->where('status', 'available')]);
        if (! empty($filters['brand'])) {
            $query->whereIn('brand', (array) $filters['brand']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['deals'])) {
            $query->where('discount_percent', '>', 0);
        }
        if (! empty($filters['q'])) {
            foreach (preg_split('/\s+/', trim($filters['q'])) as $token) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $token).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('brand', 'like', $term)->orWhere('category', 'like', $term)->orWhere('description', 'like', $term)->orWhereHas('units', fn ($u) => $u->where('status', 'available')->where('processor', 'like', $term)));
            }
        }
        $results = $query->get()->map(function ($model) use ($filters) {
            $s = $this->summary($model);
            if (! $s['available']) {
                return empty($filters['in_stock']) && count(array_filter($filters, fn ($v, $k) => in_array($k, ['ram', 'ssd', 'grade', 'processor', 'screen', 'generation', 'keyboard', 'battery_health', 'touchscreen', 'graphics', 'resolution', 'os', 'min_price', 'max_price']) && ! empty($v), ARRAY_FILTER_USE_BOTH)) === 0 ? $s : null;
            }
            $model = $s['model'];
            $best = null;
            foreach ($model->units as $unit) {
                $unit->setRelation('model', $model);
                if (! empty($filters['processor']) && ! in_array($unit->processor_family, (array) $filters['processor'])) {
                    continue;
                }
                if (! empty($filters['generation']) && $unit->processor_generation != (int) $filters['generation']) {
                    continue;
                }
                if (! empty($filters['grade']) && ! in_array($unit->grade, (array) $filters['grade'])) {
                    continue;
                }
                if (! empty($filters['screen']) && ! in_array((float) $unit->screen_inches, array_map('floatval', (array) $filters['screen']))) {
                    continue;
                }
                if (! empty($filters['resolution']) && $unit->resolution !== $filters['resolution']) {
                    continue;
                }
                if (! empty($filters['keyboard']) && $unit->keyboard_layout !== $filters['keyboard']) {
                    continue;
                }
                if (! empty($filters['battery_health']) && ($unit->battery_health === null || $unit->battery_health < (int) $filters['battery_health'])) {
                    continue;
                }
                if (! empty($filters['touchscreen']) && ! $unit->touchscreen || ! empty($filters['graphics']) && ! $unit->dedicated_graphics) {
                    continue;
                }
                $rams = ! empty($filters['ram']) ? array_map('intval', (array) $filters['ram']) : [$unit->ram_gb];
                $ssds = ! empty($filters['ssd']) ? array_map('intval', (array) $filters['ssd']) : [$unit->ssd_gb];
                foreach ($rams as $ram) {
                    foreach ($ssds as $ssd) {
                        if (! in_array($ram, $model->supported_ram, true) || ! in_array($ssd, $model->supported_ssd, true)) {
                            continue;
                        }
                        $os = $filters['os'] ?? $unit->operating_system;
                        if (! in_array($os, $model->operating_systems, true)) {
                            continue;
                        }
                        $c = ['ram_gb' => $ram, 'ssd_gb' => $ssd, 'grade' => $unit->grade, 'processor' => $unit->processor, 'keyboard_layout' => $unit->keyboard_layout, 'operating_system' => $os, 'battery' => 'standard', 'charger' => 'included', 'warranty_months' => 12];
                        $p = $this->candidate($unit, $c, $this->components());
                        if (! $p) {
                            continue;
                        }
                        if (isset($filters['min_price']) && $filters['min_price'] !== '' && $p['price_cents'] < (float) $filters['min_price'] * 100) {
                            continue;
                        }
                        if (isset($filters['max_price']) && $filters['max_price'] !== '' && $p['price_cents'] > (float) $filters['max_price'] * 100) {
                            continue;
                        }
                        if (! $best || $p['price_cents'] < $best['price_cents']) {
                            $best = ['model' => $model, 'available' => true, 'price_cents' => $p['price_cents'], 'original_cents' => $p['original_cents'], 'unit' => $unit, 'configuration' => $c, 'stock' => $s['stock']];
                        }
                    }
                }
            }

            return $best;
        })->filter();

        return match ($filters['sort'] ?? 'featured') {
            'price_asc' => $results->sortBy('price_cents'), 'price_desc' => $results->sortByDesc('price_cents'),
            'newest' => $results->sortByDesc(fn ($s) => $s['model']->created_at->timestamp),
            'best_selling' => $results->sortByDesc(fn ($s) => $s['model']->sold_count),
            'discount' => $results->sortByDesc(fn ($s) => $s['model']->discount_percent),
            default => $results->sortByDesc(fn ($s) => $s['model']->featured),
        };
    }
}

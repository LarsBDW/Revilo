<?php

namespace App\Support;

use App\Models\Setting;

class Pricing
{
    private ?array $values = null;

    public function cents(string $key, int $fallback): int
    {
        $this->values ??= Setting::whereIn('key', ['extended_warranty_cents', 'shipping_nl_cents', 'shipping_be_cents', 'shipping_de_cents', 'free_shipping_threshold_cents'])->get()->mapWithKeys(fn ($s) => [$s->key => $s->value['cents'] ?? null])->all();

        return isset($this->values[$key]) ? (int) $this->values[$key] : $fallback;
    }

    public static function warranty(): int
    {
        return app(self::class)->cents('extended_warranty_cents', (int) config('revilo.extended_warranty_cents'));
    }

    public static function threshold(): int
    {
        return app(self::class)->cents('free_shipping_threshold_cents', (int) config('revilo.free_shipping_threshold_cents'));
    }

    public static function delivery(string $country): int
    {
        return app(self::class)->cents('shipping_'.strtolower($country).'_cents', (int) config('revilo.shipping_cents.'.$country, 695));
    }

    public static function shipping(int $net, string $country): int
    {
        return $net >= self::threshold() ? 0 : self::delivery($country);
    }
}

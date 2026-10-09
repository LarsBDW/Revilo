<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductModel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['description' => 'array', 'images' => 'array', 'supported_ram' => 'array', 'supported_ssd' => 'array', 'operating_systems' => 'array', 'specifications' => 'array', 'seo' => 'array', 'published' => 'boolean', 'featured' => 'boolean'];
    }

    public function units()
    {
        return $this->hasMany(InventoryUnit::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function text(string $field = 'description'): string
    {
        $values = $this->$field;

        return is_array($values) ? ($values[app()->getLocale()] ?? $values['nl'] ?? '') : (string) $values;
    }

    public function image(): string
    {
        return $this->images[0] ?? '/assets/products/placeholder.svg';
    }
}

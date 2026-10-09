<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function valueFor(string $key, mixed $fallback = null): mixed
    {
        return self::where('key', $key)->first()?->value ?? $fallback;
    }

    public static function translated(string $key, string $fallback = ''): string
    {
        $v = self::valueFor($key, []);

        return $v[app()->getLocale()] ?? $v['nl'] ?? $fallback;
    }
}

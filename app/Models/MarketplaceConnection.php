<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceConnection extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public static function forProvider(string $provider): array
    {
        return static::where('provider', $provider)->first()?->credentials ?? [];
    }
}

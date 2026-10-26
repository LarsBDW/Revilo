<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }

    public function unit()
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }

    public function listings()
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function actions()
    {
        return $this->hasMany(MarketplaceAction::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['purchase_cents', 'refurbishment_cents', 'upgrade_cost_cents', 'components'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'components' => 'array'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function unit()
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }
}

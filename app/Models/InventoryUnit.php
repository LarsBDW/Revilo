<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryUnit extends Model
{
    public const STATUSES = ['available', 'reserved', 'sold', 'under_repair', 'awaiting_inspection', 'returned', 'archived'];

    protected $guarded = ['id'];

    protected $hidden = ['serial_number', 'serial_hash', 'purchase_cents', 'refurbishment_cents', 'internal_notes', 'purchase_date'];

    protected function casts(): array
    {
        return ['serial_number' => 'encrypted', 'images' => 'array', 'accessories' => 'array', 'touchscreen' => 'boolean', 'dedicated_graphics' => 'boolean', 'purchase_date' => 'date'];
    }

    public function model()
    {
        return $this->belongsTo(ProductModel::class, 'product_model_id');
    }

    public function tests()
    {
        return $this->hasMany(UnitTestResult::class);
    }

    public function history()
    {
        return $this->hasMany(InventoryHistory::class);
    }
}

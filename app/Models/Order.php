<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['internal_notes', 'idempotency_key'];

    protected function casts(): array
    {
        return ['shipping_address' => 'array', 'is_test' => 'boolean', 'paid_at' => 'datetime', 'reservation_expires_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reservations()
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}

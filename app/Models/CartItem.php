<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    public function model()
    {
        return $this->belongsTo(ProductModel::class, 'product_model_id');
    }

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }
}

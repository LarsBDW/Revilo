<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved' => 'boolean', 'verified_purchase' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function model()
    {
        return $this->belongsTo(ProductModel::class, 'product_model_id');
    }
}

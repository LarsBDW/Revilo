<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceAction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'snapshot' => 'array', 'results' => 'array', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}

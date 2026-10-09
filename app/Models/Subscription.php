<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'consent_at' => 'datetime', 'confirmed_at' => 'datetime', 'unsubscribed_at' => 'datetime', 'notified_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitTestResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tested_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Component extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['cost_cents'];

    protected function casts(): array
    {
        return ['compatible_models' => 'array', 'specifications' => 'array'];
    }

    public function available(): int
    {
        return max(0, $this->quantity - $this->reserved);
    }

    public function compatible(int $modelId): bool
    {
        return in_array($modelId, $this->compatible_models ?? [], true);
    }
}

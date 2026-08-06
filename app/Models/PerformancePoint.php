<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformancePoint extends Model
{
    protected $fillable = ['month', 'value', 'position'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One material or labor line of a change order: quantity (or hours) at a unit cost (or hourly rate). */
class ChangeOrderLine extends Model
{
    public $timestamps = false;

    public const MATERIAL = 'material';

    public const LABOR = 'labor';

    protected $fillable = ['change_order_id', 'kind', 'description', 'quantity', 'unit', 'unit_cost', 'total', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2', 'total' => 'decimal:2'];
    }
}

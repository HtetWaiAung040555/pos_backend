<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionFocAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'promotion_id',
        'product_id',
        'product_unit_id',
        'unit_id',
        'unit_name',
        'unit_quantity',
        'base_quantity',
        'conversion_to_base',
        'allocated_qty',
        'used_qty',
        'allocated_warehouse_id',
    ];

    protected $casts = [
        'unit_quantity' => 'decimal:6',
        'base_quantity' => 'decimal:6',
        'conversion_to_base' => 'decimal:6',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function remainingQty()
    {
        return max(0, $this->allocated_qty - $this->used_qty);
    }
}

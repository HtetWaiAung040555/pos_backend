<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'promotion_id',
        'group_no',
        'tier',
        'product_id',
        'product_unit_id',
        'unit_id',
        'unit_name',
        'conversion_to_base',
        'condition_type',
        'operator',
        'target_value',
        'target_value_to',
    ];

    protected $casts = [
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
}

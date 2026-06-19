<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'promotion_id',
        'tier',
        'reward_type',
        'product_id',
        'product_unit_id',
        'unit_id',
        'unit_name',
        'conversion_to_base',
        'reward_value',
        'override_price',
        'reward_qty',
    ];

    protected $casts = [
        'conversion_to_base' => 'decimal:6',
        'reward_value' => 'decimal:2',
        'override_price' => 'decimal:2',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'tier',
        'reward_type',
        'product_id',
        'reward_value',
        'reward_qty',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

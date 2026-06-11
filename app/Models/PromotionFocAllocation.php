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
        'allocated_qty',
        'used_qty',
        'allocated_warehouse_id',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function remainingQty()
    {
        return max(0, $this->allocated_qty - $this->used_qty);
    }
}

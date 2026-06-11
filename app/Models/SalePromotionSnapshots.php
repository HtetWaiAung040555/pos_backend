<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePromotionSnapshot extends Model
{
    use HasFactory;

    protected $table = 'sale_promotion_snapshots';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'sale_id',
        'promotion_id',
        'promo_type',
        'condition_type',
        'promo_mode',
        'snapshot_json',
        'discount_amount',
        'final_amount',
    ];

    public function sale() {
        return $this->belongsTo(Sale::class);
    }

    public function promotion() {
        return $this->belongsTo(Promotion::class);
    }
    
}

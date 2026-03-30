<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceChangeProduct extends Model
{
    use HasFactory;

    protected $table = 'price_changes_products';
    protected $primaryKey = 'id';
    protected $fillable = [
        'price_change_id',
        'product_id',
        'old_price',
        'new_price',
    ];

    public function priceChange()
    {
        return $this->belongsTo(PriceChange::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

}

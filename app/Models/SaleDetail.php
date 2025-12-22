<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleDetail extends Model
{
    use HasFactory;

    protected $table = 'sale_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'sale_id',
        'inventory_id',
        'product_id',
        'quantity',
        'price',
        'discount_amount',
        'discount_price',
        'total',
        'promotion_id',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function inventory(){
        return $this->belongsTo(Inventory::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

}

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
        'product_unit_id',
        'unit_id',
        'unit_name',
        'unit_quantity',
        'base_quantity',
        'conversion_to_base',
        'unit_barcode',
        'price_range_id',
        'price',
        'discount_amount',
        'discount_price',
        'total',
        'promotion_id',
        'is_foc',
        'reward_id',
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

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function priceRange()
    {
        return $this->belongsTo(ProductUnitPriceRange::class, 'price_range_id');
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

}

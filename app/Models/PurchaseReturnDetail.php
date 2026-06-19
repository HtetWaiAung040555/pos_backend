<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturnDetail extends Model
{
    use HasFactory;

    protected $table = 'purchase_return_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'purchase_return_id',
        'purchase_detail_id',
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
        'total',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseDetail()
    {
        return $this->belongsTo(purchaseDetail::class);
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
}

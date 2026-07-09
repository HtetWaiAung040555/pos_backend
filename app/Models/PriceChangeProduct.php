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
        'branch_id',
        'branch_product_id',
        'product_id',
        'product_unit_id',
        'branch_product_unit_price_id',
        'product_unit_price_range_id',
        'branch_product_unit_price_range_id',
        'unit_id',
        'unit_name',
        'conversion_to_base',
        'min_qty',
        'max_qty',
        'old_price',
        'new_price',
    ];

    protected $casts = [
        'conversion_to_base' => 'decimal:6',
        'min_qty' => 'decimal:6',
        'max_qty' => 'decimal:6',
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
    ];

    public function priceChange()
    {
        return $this->belongsTo(PriceChange::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchProduct()
    {
        return $this->belongsTo(BranchProduct::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function branchProductUnitPrice()
    {
        return $this->belongsTo(BranchProductUnitPrice::class);
    }

    public function productUnitPriceRange()
    {
        return $this->belongsTo(ProductUnitPriceRange::class);
    }

    public function branchProductUnitPriceRange()
    {
        return $this->belongsTo(BranchProductUnitPriceRange::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

}

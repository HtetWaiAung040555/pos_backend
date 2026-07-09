<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'unit_id',
        'sec_prop',
        'category_id',
        'purchase_price',
        'old_purchase_price',
        'price',
        'old_price',
        'image',
        'barcode',
        'default_product_unit_id',
        'uom_enabled',
        'status_id',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'uom_enabled' => 'boolean',
    ];

    public function unit(){
        return $this->belongsTo(Unit::class);
    }

    public function productUnits()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function defaultProductUnit()
    {
        return $this->belongsTo(ProductUnit::class, 'default_product_unit_id');
    }

    public function branchProducts()
    {
        return $this->hasMany(BranchProduct::class);
    }

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function status() {
        return $this->belongsTo(Status::class);
    }

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }

    public function promotions() {
        return $this->belongsToMany(Promotion::class, 'promotions_products', 'product_id', 'promotion_id')
            ->withPivot('product_unit_id', 'unit_id')
            ->withTimestamps();
    }

    public function priceChanges()
    {
        return $this->belongsToMany(PriceChange::class, 'price_changes_products', 'product_id','price_change_id')
        ->withPivot(
            'branch_id',
            'branch_product_id',
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
            'new_price'
        )
        ->withTimestamps();
    }

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchProductUnitPrice extends Model
{
    use HasFactory;

    protected $table = 'branch_product_unit_prices';
    protected $primaryKey = 'id';

    protected $fillable = [
        'branch_product_id',
        'product_unit_id',
        'unit_id',
        'unit_name',
        'conversion_to_base',
        'price',
        'old_price',
        'status_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'conversion_to_base' => 'decimal:6',
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
    ];

    public function branchProduct()
    {
        return $this->belongsTo(BranchProduct::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function priceRanges()
    {
        return $this->hasMany(BranchProductUnitPriceRange::class);
    }

    public function priceChangeProducts()
    {
        return $this->hasMany(PriceChangeProduct::class);
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

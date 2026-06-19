<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use HasFactory;

    protected $table = 'product_units';
    protected $primaryKey = 'id';
    protected $fillable = [
        'product_id',
        'unit_id',
        'barcode',
        'conversion_to_base',
        'price',
        'old_price',
        'purchase_price',
        'old_purchase_price',
        'is_base_unit',
        'is_default_sale_unit',
        'sort_order',
        'status_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'conversion_to_base' => 'decimal:6',
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'purchase_price' => 'decimal:6',
        'old_purchase_price' => 'decimal:6',
        'is_base_unit' => 'boolean',
        'is_default_sale_unit' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function priceRanges()
    {
        return $this->hasMany(ProductUnitPriceRange::class);
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

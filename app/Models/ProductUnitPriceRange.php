<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUnitPriceRange extends Model
{
    use HasFactory;

    protected $table = 'product_unit_price_ranges';
    protected $primaryKey = 'id';
    protected $fillable = [
        'product_unit_id',
        'min_qty',
        'max_qty',
        'price',
        'old_price',
        'status_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'min_qty' => 'decimal:6',
        'max_qty' => 'decimal:6',
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
    ];

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
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

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
        'status_id',
        'created_by',
        'updated_by'
    ];

    public function unit(){
        return $this->belongsTo(Unit::class);
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
        return $this->belongsToMany(Promotion::class, 'promotions_products', 'product_id', 'promotion_id');
    }

    public function priceChanges()
    {
        return $this->belongsToMany(PriceChange::class, 'price_changes_products', 'product_id','price_change_id')
        ->withPivot('type', 'old_price', 'new_price')
        ->withTimestamps();
    }

}

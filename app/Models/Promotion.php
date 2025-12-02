<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'description', 
        'discount_type', 
        'discount_value',
        'start_at', 
        'end_at', 
        'status_id', 
        'created_by', 
        'updated_by'
    ];

    public function status() {
        return $this->belongsTo(Status::class);
    }

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }

    public function products() {
        return $this->belongsToMany(Product::class, 'promotions_products', 'promotion_id', 'product_id');
    }
}

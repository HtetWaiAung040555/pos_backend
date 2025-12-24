<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceChange extends Model
{
    use HasFactory;

    protected $table = 'price_changes';
    protected $primaryKey = 'id';
    protected $fillable = [
        'description',
        'start_at',
        'end_at',
        'status_id',
        'void_at',
        'void_by',
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
        return $this->belongsToMany(Product::class, 'price_changes_products', 'price_change_id', 'product_id');
    }

    public function voidBy(){
        return $this->belongsTo(User::class, 'void_by');
    }
}

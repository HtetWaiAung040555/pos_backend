<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $table = 'inventories';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'qty',
        'product_id',
        'warehouse_id',
        'created_by',
        'updated_by'
    ];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalInventory extends Model
{
    use HasFactory;

    protected $table = 'local_inventories';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'price',
        'qty',
        'image',
        'barcode',
        'created_by',
        'updated_by'
    ];

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }
}

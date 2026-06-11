<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionWarehouse extends Model
{
    use HasFactory;

    protected $table = 'promotion_warehouses';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'promotion_id',
        'warehouse_id',
    ];

    public function promotion() {
        return $this->belongsTo(Promotion::class);
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }
    
}

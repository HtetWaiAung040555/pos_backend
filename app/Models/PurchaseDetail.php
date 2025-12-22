<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseDetail extends Model
{
    use HasFactory;

    protected $table = 'purchase_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'purchase_id',
        'inventory_id',
        'product_id',
        'quantity',
        'price',
        'total'
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function inventory(){
        return $this->belongsTo(Inventory::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransaction extends Model
{
    use HasFactory;

    protected $table = "stock_transactions";
    protected $primaryKey = "id";
    protected $fillable = [
        "inventory_id",
        "reference_id",
        "reference_type",
        "reference_date",
        "quantity_change",
        "reason",
        "type",
        "created_by",
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, "reference_id");
    }

    // public function purchase()
    // {
    //     return $this->belongsTo(Purchase::class, 'reference_id');
    // }

    public function createdBy()
    {
        return $this->belongsTo(User::class, "created_by");
    }
}

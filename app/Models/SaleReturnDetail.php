<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleReturnDetail extends Model
{
    use HasFactory;

    protected $table = 'sale_return_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'sale_return_id',
        'sale_detail_id',
        'product_id',
        'quantity',
        'price',
        'total',
    ];

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleDetail()
    {
        return $this->belongsTo(saleDetail::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

}

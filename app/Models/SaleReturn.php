<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $table = 'sale_returns';
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'sale_id',
        'customer_id',
        'warehouse_id',
        'total_amount',
        'remark',
        'return_date',
        'payment_id',
        'status_id',
        'created_by',
        'updated_by',
        'void_at',
        'void_by',
    ];

    protected $casts = [
        'return_date' => 'datetime',
        'void_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale_return) {
            $dateCode = Carbon::now()->format('dmy');

            $lastSale = self::where('id', 'like', "SR-{$dateCode}%")
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = $lastSale
                ? intval(substr($lastSale->id, -5)) + 1
                : 1;

            $sale_return->id = 'SR-' . $dateCode . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        });
    }

    public function sale(){
        return $this->belongsTo(Sale::class);
    }

    public function details()
    {
        return $this->hasMany(SaleReturnDetail::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_id');
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

    public function voidBy()
    {
        return $this->belongsTo(User::class, 'void_by');
    }
}

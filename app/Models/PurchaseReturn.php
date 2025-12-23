<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $table = 'purchase_returns';
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'purchase_id',
        'supplier_id',
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

        static::creating(function ($purchase_return) {
            $dateCode = Carbon::now()->format('dmy');

            $lastPurchase = self::where('id', 'like', "PR-{$dateCode}%")
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = $lastPurchase
                ? intval(substr($lastPurchase->id, -5)) + 1
                : 1;

            $purchase_return->id = 'PR-' . $dateCode . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        });
    }

    public function purchase(){
        return $this->belongsTo(Purchase::class);
    }

    public function details()
    {
        return $this->hasMany(PurchaseReturnDetail::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
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

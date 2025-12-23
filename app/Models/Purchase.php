<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Purchase extends Model
{
    use HasFactory;

    protected $table = 'purchases';
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'warehouse_id',
        'supplier_id',
        'total_amount',
        'payment_id',
        'status_id',
        'remark',
        'purchase_date',
        'created_by',
        'updated_by',
        'void_at',
        'void_by',
    ];

    protected $casts = [
        'purchase_date' => 'datetime',
        'void_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($purchase) {

            $dateCode = Carbon::now()->format('ymd');

            $lastPurchase = self::where('id', 'like', "P-{$dateCode}%")
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $nextNumber = $lastPurchase
                ? intval(substr($lastPurchase->id, -5)) + 1
                : 1;

            $purchase->id = 'P-' . $dateCode . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        });
    }

    public function details()
    {
        return $this->hasMany(PurchaseDetail::class);
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

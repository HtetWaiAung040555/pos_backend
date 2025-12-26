<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $table = 'sales';
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'warehouse_id',
        'customer_id',
        'total_amount',
        'paid_amount',
        'due_amount',
        'payment_id',
        'status_id',
        'remark',
        'sale_date',
        'created_by',
        'updated_by',
        'void_at',
        'void_by',
    ];

    protected $casts = [
        'sale_date' => 'datetime',
        'void_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            $dateCode = Carbon::now()->format('dmy');

            $userId = $sale->created_by;

            $lastSale = self::where('id', 'like', "S-{$userId}{$dateCode}%")
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = $lastSale
                ? intval(substr($lastSale->id, -3)) + 1
                : 1;

            $sale->id = 'S-' . $userId . $dateCode . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        });
    }

    public function details()
    {
        return $this->hasMany(SaleDetail::class);
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

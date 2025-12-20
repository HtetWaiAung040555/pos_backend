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
        'sale_date' => 'datetime',
        'void_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            $dateCode = Carbon::now()->format('dmy');

            $lastSale = self::where('id', 'like', "S-{$dateCode}%")
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = $lastSale
                ? intval(substr($lastSale->id, -5)) + 1
                : 1;

            $sale->id = 'S-' . $dateCode . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
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

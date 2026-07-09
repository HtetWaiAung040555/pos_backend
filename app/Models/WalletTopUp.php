<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTopUp extends Model
{
    use HasFactory;

    protected $table = 'wallet_topup';
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'customer_id',
        'type',
        'amount',
        'payment_id',
        'status_id',
        'remark',
        'pay_date',
        'is_synced',
        'synced_at',
        'created_by',
        'updated_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($wallet) {
            
            if (!empty($wallet->id)) {
                return;
            }

            $dateCode = Carbon::now()->format('dmy');
            $userId = $wallet->created_by;

            $prefix = "W-{$userId}{$dateCode}";

            $topup = self::where('id', 'like', "{$prefix}%")
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = $topup ? intval(substr($topup->id, -3)) + 1 : 1;

            $wallet->id = $prefix.str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

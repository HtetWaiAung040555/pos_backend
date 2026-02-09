<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id',
        'name',
        'phone',
        'address',
        'status_id',
        'is_default',
        'balance',
        'created_by',
        'updated_by'
    ];

    public function status() {
        return $this->belongsTo(Status::class);
    }

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }

    // protected static function boot()
    // {
    //     parent::boot();

    //     static::creating(function ($customer) {
    //         if (empty($customer->id)) {
    //             $last = self::latest('id')->first();
    //             if ($last) {
    //                 $number = intval(str_replace('FMC-', '', $last->id)) + 1;
    //             } else {
    //                 $number = 1;
    //             }
    //             $customer->id = 'FMC-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    //         }
    //     });
    // }
}

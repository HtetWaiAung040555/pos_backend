<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id',
        'name',
        'phone',
        'address',
        'status_id',
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($supplier) {

            if (!empty($supplier->id)) {
                return;
            }

            $prefix = ''; // add your prefix here

            // Get last ID with this prefix
            $lastId = self::where('id', 'like', $prefix . '%')
                ->orderBy('id', 'desc')
                ->value('id');

            if ($lastId) {
                // KBAM-000123 → 123
                $number = (int) str_replace($prefix, '', $lastId) + 1;
            } else {
                $number = 1;
            }

            // Generate KBAM-000001
            $supplier->id = $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
        });
    }


}

<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceChange extends Model
{
    use HasFactory;

    protected $table = 'price_changes';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'description',
        'type',
        'start_at',
        'end_at',
        'ended_at',
        'ended_by',
        'end_reason',
        'status_id',
        'void_at',
        'void_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'ended_at' => 'datetime',
        'void_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($price_change) {

            if ($price_change->id) {
                return;
            }

            $dateCode = Carbon::now()->format('dmy');

            $userId = $price_change->created_by;

            $last = self::where('id', 'like', "PC-{$userId}{$dateCode}%")
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = $last ? intval(substr($last->id, -3)) + 1 : 1;

            $price_change->id = 'PC-'.$userId.$dateCode.str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        });
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function products()
    {
        return $this->hasMany(PriceChangeProduct::class);
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

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}

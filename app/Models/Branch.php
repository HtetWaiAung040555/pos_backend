<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $table = 'branches';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'phone',
        'location',
        'warehouse_id',
        'status_id',
        'created_by',
        'updated_by'
    ];

    public function warehouse(){
        return $this->belongsTo(Warehouse::class);
    }

    public function status() {
        return $this->belongsTo(Status::class);
    }

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }
    
}

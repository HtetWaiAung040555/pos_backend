<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Counter extends Model
{

    use HasFactory;

    protected $table = 'counters';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'desc',
        'branch_id',
        'status_id',
        'created_by',
        'updated_by'
    ];

    public function branch() {
        return $this->belongsTo(Branch::class);
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

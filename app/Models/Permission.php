<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $table = 'permissions';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'action',
        'desc',
        'created_by',
        'updated_by'
    ];

    public function createdBy() { 
        return $this->belongsTo(User::class, 'created_by'); 
    }

    public function updatedBy() { 
        return $this->belongsTo(User::class, 'updated_by'); 
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
    
}

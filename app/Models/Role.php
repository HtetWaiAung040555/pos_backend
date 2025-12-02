<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'desc',
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

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users() {
        return $this->belongsToMany(User::class, 'user_role', 'role_id', 'user_id');
    }
    
}

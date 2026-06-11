<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionBranch extends Model
{
    use HasFactory;

    protected $table = 'promotion_branches';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'promotion_id',
        'branch_id',
    ];

    public function promotion() {
        return $this->belongsTo(Promotion::class);
    }

    public function branch() {
        return $this->belongsTo(Branch::class);
    }
    
}

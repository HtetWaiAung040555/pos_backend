<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionExecutionLog extends Model
{
    use HasFactory;

    protected $table = 'promotion_execution_logs';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'sale_id',
        'promotion_id',
        'rule_matched_json',
        'result_json',
    ];

    public function sale() {
        return $this->belongsTo(Sale::class);
    }

    public function promotion() {
        return $this->belongsTo(Promotion::class);
    }
    
}

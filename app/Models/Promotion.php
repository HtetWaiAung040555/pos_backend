<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'name',
        'description',
        'promo_type',
        'condition_type',
        'promo_mode',
        'max_reward_value',
        'override_price',
        'branch_scope_type',
        'warehouse_scope_type',
        'discount_type',
        'discount_value',
        'start_at',
        'end_at',
        'status_id',
        'void_at',
        'void_by',
        'created_by',
        'updated_by',
        'is_synced',
        'synced_at'
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

    public function products() {
        return $this->belongsToMany(Product::class, 'promotions_products', 'promotion_id', 'product_id')
            ->withPivot('product_unit_id', 'unit_id', 'max_qty_per_sales_order')
            ->withTimestamps();
    }

    public function voidBy(){
        return $this->belongsTo(User::class, 'void_by');
    }

    public function conditions()
    {
        return $this->hasMany(PromotionCondition::class);
    }

    public function rewards()
    {
        return $this->hasMany(PromotionReward::class);
    }

    public function focAllocations()
    {
        return $this->hasMany(PromotionFocAllocation::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'promotion_branches', 'promotion_id', 'branch_id');
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'promotion_warehouses', 'promotion_id', 'warehouse_id');
    }
}

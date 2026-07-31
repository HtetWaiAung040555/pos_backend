<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    private const CODE_SEGMENT_LENGTH = 2;

    protected $table = 'categories';

    protected $primaryKey = 'id';

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'sort_order',
        'status_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Category $category) {
            if ($category->code === null || trim((string) $category->code) === '') {
                $category->code = static::generateCode(
                    $category->parent_id === null
                        ? null
                        : (int) $category->parent_id
                );

                return;
            }

            $category->code = trim((string) $category->code);
        });
    }

    public static function generateCode(?int $parentId = null): string
    {
        $prefix = '';

        if ($parentId !== null) {
            $parent = static::query()
                ->lockForUpdate()
                ->findOrFail($parentId);

            if ($parent->code === null || trim((string) $parent->code) === '') {
                throw new DomainException(
                    'The parent category must have a code before a child code can be generated.'
                );
            }

            $prefix = trim((string) $parent->code);
        }

        $expectedCodeLength = strlen($prefix) + self::CODE_SEGMENT_LENGTH;
        $lastSequence = static::query()
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId)
            )
            ->whereNotNull('code')
            ->lockForUpdate()
            ->pluck('code')
            ->map(function ($code) use ($prefix, $expectedCodeLength) {
                $code = trim((string) $code);

                if (
                    strlen($code) !== $expectedCodeLength
                    || ! str_starts_with($code, $prefix)
                ) {
                    return null;
                }

                $sequence = substr($code, strlen($prefix));

                return ctype_digit($sequence) ? (int) $sequence : null;
            })
            ->filter(fn ($sequence) => $sequence !== null)
            ->max() ?? 0;
        $nextSequence = $lastSequence + 1;
        $maximumSequence = (10 ** self::CODE_SEGMENT_LENGTH) - 1;

        if ($nextSequence > $maximumSequence) {
            throw new DomainException(
                'No more automatic category codes are available under the selected parent.'
            );
        }

        return $prefix.str_pad(
            (string) $nextSequence,
            self::CODE_SEGMENT_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

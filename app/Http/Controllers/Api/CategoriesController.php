<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoriesController extends Controller
{
    public function index()
    {
        $categories = Category::with([
            'parent:id,name,code',
            'status',
            'createdBy',
            'updatedBy',
        ])
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function tree()
    {
        $categories = Category::with(['status', 'createdBy', 'updatedBy'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $categoriesByParent = $categories->groupBy(
            fn (Category $category) => $category->parent_id ?? 'root'
        );

        return CategoryResource::collection(
            $this->buildTree($categoriesByParent)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer|exists:categories,id',
            'name' => 'required|string|max:255',
            'code' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'code')],
            'sort_order' => 'nullable|integer|min:0',
            'status_id' => 'required|exists:statuses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $parentId = isset($validated['parent_id'])
            ? (int) $validated['parent_id']
            : null;

        $this->ensureSiblingNameIsUnique(
            $validated['name'],
            $parentId
        );
        $this->ensureParentHasNoProducts($parentId);

        try {
            $category = DB::transaction(fn () => Category::create([
                'parent_id' => $parentId,
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
                'status_id' => $validated['status_id'],
                'created_by' => $validated['created_by'],
                'updated_by' => $validated['updated_by'] ?? $validated['created_by'],
            ]), 3);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'code' => $exception->getMessage(),
            ]);
        }

        return new CategoryResource($category->fresh([
            'parent:id,name,code',
            'children',
            'status',
            'createdBy',
            'updatedBy',
        ]));
    }

    public function show(string $id)
    {
        $category = Category::with([
            'parent:id,name,code',
            'children.status',
            'children.createdBy',
            'children.updatedBy',
            'status',
            'createdBy',
            'updatedBy',
        ])->findOrFail($id);

        return new CategoryResource($category);
    }

    public function update(Request $request, string $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'parent_id' => 'sometimes|nullable|integer|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'code')->ignore($category->id),
            ],
            'sort_order' => 'sometimes|required|integer|min:0',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $parentId = array_key_exists('parent_id', $validated)
            ? ($validated['parent_id'] === null ? null : (int) $validated['parent_id'])
            : $category->parent_id;
        $name = $validated['name'] ?? $category->name;

        $this->ensureParentDoesNotCreateCycle($category, $parentId);
        $this->ensureParentHasNoProducts($parentId);
        $this->ensureSiblingNameIsUnique(
            $name,
            $parentId,
            (int) $category->id
        );

        $data = array_intersect_key($validated, array_flip([
            'parent_id',
            'name',
            'code',
            'sort_order',
            'status_id',
            'updated_by',
        ]));

        $category->update($data);

        return new CategoryResource($category->fresh([
            'parent:id,name,code',
            'children',
            'status',
            'createdBy',
            'updatedBy',
        ]));
    }

    public function destroy(string $id)
    {
        $category = Category::withCount(['children', 'products'])->findOrFail($id);
        $blockers = [];

        if ($category->children_count > 0) {
            $blockers[] = 'child categories';
        }

        if ($category->products_count > 0) {
            $blockers[] = 'products';
        }

        if ($blockers !== []) {
            return response()->json([
                'error' => 'Category cannot be deleted because it is already used.',
                'blockers' => $blockers,
            ], 422);
        }

        try {
            $category->delete();

            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (QueryException) {
            return response()->json([
                'error' => 'Category cannot be deleted because it is already used.',
            ], 422);
        }
    }

    private function buildTree(
        Collection $categoriesByParent,
        int|string $parentId = 'root'
    ): Collection {
        return $categoriesByParent
            ->get($parentId, collect())
            ->map(function (Category $category) use ($categoriesByParent) {
                $category->setRelation(
                    'children',
                    $this->buildTree($categoriesByParent, (int) $category->id)
                );

                return $category;
            })
            ->values();
    }

    private function ensureParentDoesNotCreateCycle(
        Category $category,
        ?int $parentId
    ): void {
        if ($parentId === null) {
            return;
        }

        $parentIds = Category::pluck('parent_id', 'id');
        $currentId = $parentId;
        $visited = [];

        while ($currentId !== null) {
            if ($currentId === (int) $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A category cannot be moved under itself or one of its descendants.',
                ]);
            }

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent belongs to an invalid category hierarchy.',
                ]);
            }

            $visited[$currentId] = true;
            $nextId = $parentIds->get($currentId);
            $currentId = $nextId === null ? null : (int) $nextId;
        }
    }

    private function ensureSiblingNameIsUnique(
        string $name,
        ?int $parentId,
        ?int $ignoredCategoryId = null
    ): void {
        $query = Category::where('name', $name)
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId)
            )
            ->when(
                $ignoredCategoryId !== null,
                fn ($query) => $query->whereKeyNot($ignoredCategoryId)
            );

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A category with this name already exists under the selected parent.',
            ]);
        }
    }

    private function ensureParentHasNoProducts(?int $parentId): void
    {
        if (
            $parentId !== null
            && Category::whereKey($parentId)->whereHas('products')->exists()
        ) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category with assigned products cannot have child categories.',
            ]);
        }
    }
}

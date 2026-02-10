<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CategoriesController extends Controller
{
    public function index()
    {
        $categories = Category::with(['status', 'createdBy', 'updatedBy'])->get();
        return CategoryResource::collection($categories);
    }

    // public function store(Request $request)
    // {
    //     $request->validate([
    //         'name' => 'required|string|max:255',
    //         'status_id' => 'required|exists:statuses,id',
    //         'created_by' => 'required|exists:users,id',
    //         'updated_by' => 'nullable|exists:users,id'
    //     ]);

    //     $category = Category::create([
    //         'name' => $request->name,
    //         'status_id' => $request->status_id,
    //         'created_by' => $request->created_by,
    //         'updated_by' => $request->updated_by ?? $request->created_by
    //     ]);

    //     return new CategoryResource($category->fresh(['status', 'createdBy', 'updatedBy']));
    // }

    public function store(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/categories');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json("data") as $item) {
                Category::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'status_id' => $item['status']['id'],
                        'created_by' => $item['created_by']['id'],
                        'created_at' => $item['created_at'],
                        'updated_by' => $request->updated_by ?? $request->created_by
                    ]
                );
            }

            $categories = Category::with(['status', 'createdBy', 'updatedBy'])->get();
            $allCategories = CategoryResource::collection($categories);

            return response()->json([
                'message' => 'success',
                'data' => $allCategories
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred during sync',
                'error'   => $e->getMessage()
            ], 500);

        }
    }

    public function show(string $id)
    {
        $category = Category::with(['status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new CategoryResource($category);
    }

    public function update(Request $request, string $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'status_id', 'updated_by']);
        $category->update($data);

        return new CategoryResource($category->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            Category::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Payment Method cannot be deleted'], 400);
        }
    }
}

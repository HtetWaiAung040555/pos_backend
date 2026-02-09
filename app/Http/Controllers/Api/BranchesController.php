<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Support\Facades\Http;

class BranchesController extends Controller
{

    public function index()
    {
        $branches = Branch::with(['warehouse','status','createdBy', 'updatedBy'])->get();
        return BranchResource::collection($branches);
    }


    public function store(Request $request)
    {

        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/branches');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json('data') as $item) {
                Branch::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'phone' => $item['phone'],
                        'location' => $item['location'],
                        'warehouse_id' => $item['warehouse']['id'],
                        'status_id' => $item['status']['id'],
                        'created_by' => $item['created_by']['id'],
                        'updated_by' => $request->updated_by ?? $request->created_by
                    ]
                );
                
            }
            return response()->json(['message' => 'success'],200);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred during sync',
                'error'   => $e->getMessage()
            ], 500);
            
        }
    
    }

 
    public function show(string $id)
    {
        $branch = Branch::with(['warehouse','status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new BranchResource($branch);
    }


    public function update(Request $request, string $id)
    {
        $branch = Branch::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:50',
            'location' => 'sometimes|required|string|max:255',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'phone', 'location', 'warehouse_id', 'status_id', 'updated_by']);

        $branch->update($data);

        return new BranchResource($branch->fresh(['warehouse', 'status', 'createdBy', 'updatedBy']));
    }

    
    public function destroy(string $id)
    {
        try {
            Branch::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Branch cannot be deleted'], 400);
        }
    }

}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\BranchResource;
use App\Models\Branch;

class BranchesController extends Controller
{

    public function index()
    {
        $branches = Branch::with(['warehouse','status','createdBy', 'updatedBy'])->get();
        return BranchResource::collection($branches);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'location' => 'required|string|max:255',
            'warehouse_id' => 'required|exists:warehouses,id',
            'status_id' => 'required|exists:statuses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);
    
        $branch = Branch::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'location' => $request->location,
            'warehouse_id' => $request->warehouse_id,
            'status_id' => $request->status_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);
    
        return new BranchResource($branch->fresh(['warehouse', 'status', 'createdBy', 'updatedBy']));
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

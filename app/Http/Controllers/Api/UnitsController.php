<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitsController extends Controller
{
    public function index()
    {
        $units = Unit::with(['status','createdBy', 'updatedBy'])->get();
        return UnitResource::collection($units);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'status_id' => 'required|exists:statuses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);
    
        $unit = Unit::create([
            'name' => $request->name,
            'status_id' => $request->status_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);
    
        return new UnitResource($unit->fresh(['status', 'createdBy', 'updatedBy']));
    }

 
    public function show(string $id)
    {
        $unit = Unit::with(['status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new UnitResource($unit);
    }


    public function update(Request $request, string $id)
    {
        $unit = Unit::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'status_id', 'updated_by']);

        $unit->update($data);

        return new UnitResource($unit->fresh(['status', 'createdBy', 'updatedBy']));
    }

    
    public function destroy(string $id)
    {
        try {
            Unit::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Branch cannot be deleted'], 400);
        }
    }
}

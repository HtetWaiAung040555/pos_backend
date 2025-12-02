<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Counter;
use App\Http\Resources\CounterResource;

class CountersController extends Controller
{
    public function index()
    {
        $counters = Counter::with(['branch', 'status', 'createdBy', 'updatedBy'])->get();
        return CounterResource::collection($counters);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'desc' => 'nullable|string|max:1000',
            'branch_id' => 'required|exists:branches,id',
            'status_id' => 'required|exists:statuses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $counter = Counter::create([
            'name' => $request->name,
            'desc' => $request->desc,
            'branch_id' => $request->branch_id,
            'status_id' => $request->status_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by,
        ]);

        return new CounterResource($counter->fresh(['branch', 'status', 'createdBy', 'updatedBy']));
    }

    public function show(string $id)
    {
        $counter = Counter::with(['branch', 'status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new CounterResource($counter);
    }

    public function update(Request $request, string $id)
    {
        $counter = Counter::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'desc' => 'nullable|string|max:1000',
            'branch_id' => 'sometimes|required|exists:branches,id',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $data = $request->only(['name', 'desc', 'branch_id', 'status_id', 'updated_by']);
        $counter->update($data);

        return new CounterResource($counter->fresh(['branch', 'status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            Counter::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Counter cannot be deleted'], 400);
        }
    }
    
}

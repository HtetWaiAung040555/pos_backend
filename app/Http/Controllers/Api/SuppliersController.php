<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SuppliersController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::with(['status', 'createdBy', 'updatedBy'])->get();
        return SupplierResource::collection($suppliers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'status_id' => 'required|exists:statuses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $supplier = Supplier::create([
            'id' => $request->id,
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'status_id' => $request->status_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);

        return new SupplierResource($supplier->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function show(string $id)
    {
        $supplier = Supplier::with(['status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new SupplierResource($supplier);
    }

    public function update(Request $request, string $id)
    {
        $supplier = Supplier::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'address' => 'sometimes|string|max:255',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'phone', 'address', 'status_id', 'updated_by']);
        $supplier->update($data);

        return new SupplierResource($supplier->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            Supplier::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Supplier cannot be deleted', 'details' => $e->getMessage()], 400);
        }
    }
}

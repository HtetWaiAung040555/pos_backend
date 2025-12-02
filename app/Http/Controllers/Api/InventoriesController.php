<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Http\Resources\InventoryResource;

class InventoriesController extends Controller
{
    public function index()
    {
        $inventories = Inventory::with(['product', 'warehouse', 'createdBy', 'updatedBy'])->get();
        return InventoryResource::collection($inventories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'nullable|string|max:255',
            'qty'        => 'required|integer|min:0',
            'product_id' => 'required|exists:products,id',
            'warehouse_id'  => 'nullable|exists:warehouses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $inventory = Inventory::create([
            'name'       => $request->name,
            'qty'        => $request->qty,
            'product_id' => $request->product_id,
            'warehouse_id'  => $request->warehouse_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by,
        ]);

        return new InventoryResource($inventory->fresh(['product','warehouse','createdBy','updatedBy']));
    }

    public function show(string $id)
    {
        $inventory = Inventory::with(['product','warehouse','createdBy','updatedBy'])->findOrFail($id);
        return new InventoryResource($inventory);
    }

    public function update(Request $request, string $id)
    {
        $inventory = Inventory::findOrFail($id);

        $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'qty'        => 'sometimes|required|integer|min:0',
            'product_id' => 'sometimes|required|exists:products,id',
            'warehouse_id'  => 'sometimes|required|exists:warehouses,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $data = $request->only(['name','qty','product_id','warehouse_id','updated_by']);
        $inventory->update($data);

        return new InventoryResource($inventory->fresh(['product','warehouse','createdBy','updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            Inventory::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Inventory cannot be deleted'], 400);
        }
    }
}

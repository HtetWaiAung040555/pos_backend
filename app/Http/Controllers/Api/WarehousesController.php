<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehousesController extends Controller
{
    public function index()
    {
        $warehouses = Warehouse::with(["createdBy", "updatedBy"])->get();
        return WarehouseResource::collection($warehouses);
    }

    // Create a new warehouse
    public function store(Request $request)
    {
        $request->validate([
            "name" => "required|string|max:255",
        ]);

        $warehouse = Warehouse::create([
            "name" => $request->input("name"),
            "created_by" => $request->input("created_by"),
            "updated_by" => $request->input("updated_by") ?? $request->input("created_by"),
        ]);

        return new WarehouseResource(
            $warehouse->fresh(["createdBy", "updatedBy"]),
        );
    }

    // Get a single warehouse
    public function show($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        return new WarehouseResource($warehouse);
    }

    // Update a warehouse
    public function update(Request $request, $id)
    {
        $request->validate([
            "name" => "required|string|max:255",
        ]);

        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update([
            "name" => $request->input("name"),
            "updated_by" => $request->input("updated_by"),
        ]);

        return new WarehouseResource(
            $warehouse->fresh(["createdBy", "updatedBy"]),
        );
    }

    // Delete a warehouse
    public function destroy($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->delete();

        return response()->json(
            ["message" => "Warehouse deleted successfully"],
            200,
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalInventory;
use App\Http\Resources\LocalInventoryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocalInventoriesController extends Controller
{
    public function index()
    {
        $localInventories = LocalInventory::with(['createdBy', 'updatedBy'])->get();
        return LocalInventoryResource::collection($localInventories);
    }

    public function store(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/products/saleproducts',['warehouse_id' => $request->warehouse_id]);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json() as $item) {
                LocalInventory::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'qty' => $item['qty'],
                        'image' => null,
                        'barcode' => $item['barcode'],
                        'created_by' => $request->created_by,
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
        $localInventory = LocalInventory::findOrFail($id);
        return new LocalInventoryResource($localInventory);
    }

    public function update(Request $request, string $id)
    {
        $localInventory = LocalInventory::findOrFail($id);
        $localInventory->update($request->all());
        return new LocalInventoryResource($localInventory);
    }

    public function destroy(string $id)
    {
        $localInventory = LocalInventory::findOrFail($id);
        $localInventory->delete();
        return response()->json(['message' => 'Local inventory deleted successfully'], 200);
    }

    // public function syncfromCloud(Request $request)
    // {
    //     DB::beginTransaction();

    //     try {

    //         $request->validate([
    //             'name' => 'required|string|max:255',
    //             'price' => 'required|numeric|max:0',
    //             'qty' => 'required|integer|min:0',
    //             'image' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
    //             'barcode' => 'nullable|string|max:255',
    //             'created_by' => 'required|exists:users,id',
    //             'updated_by' => 'nullable|exists:users,id'
    //         ]);

    //         $localInventory = LocalInventory::create([
    //             'name' => $request->name,
    //             'price' => $request->price,
    //             'qty' => $request->qty,
    //             'image' => $request->image,
    //             'barcode' => $request->barcode,
    //             'created_by' => $request->created_by,
    //             'updated_by' => $request->updated_by ?? $request->created_by
    //         ]);

    //         DB::commit();
    //         return new LocalInventoryResource($localInventory->fresh(['createdBy', 'updatedBy']));
        
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['error' => 'Failed to sync local inventory to cloud', 'details' => $e->getMessage()], 500);
    //     }
    // }
}

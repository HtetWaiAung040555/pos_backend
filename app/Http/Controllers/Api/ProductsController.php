<?php

namespace App\Http\Controllers\Api;
use App\Models\Product;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ProductsController extends Controller
{
    public function index()
    {
        $products = Product::with(['status', 'createdBy', 'updatedBy'])->get();
        return ProductResource::collection($products);
    }

    public function store(Request $request)
    {
        $request->merge([
            'unit_id' => $request->unit_id ?: null,
            'barcode' => $request->barcode ?: null,
            'category_id' => $request->category_id ?: null,
        ]);

        $request->validate([
            'name'          => 'required|string|max:255',
            'unit_id'       => 'nullable|exists:units,id',
            'sec_prop'      => 'nullable|string|max:255',
            'category_id'   => 'nullable|exists:categories,id',
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'old_purchase_price' => 'sometimes|required|numeric|min:0',
            'price'         => 'sometimes|required|numeric|min:0',
            'old_price'         => 'sometimes|required|numeric|min:0',
            'barcode'       => 'nullable|string|max:255|unique:products,barcode',
            'image'         => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'status_id'     => 'sometimes|required|exists:statuses,id',
            'created_by'    => 'sometimes|required|exists:users,id',
            'updated_by'    => 'nullable|exists:users,id'
        ]);

        // Create product first (to get ID)
        $product = Product::create([
            'name'       => $request->name,
            'unit_id'    => $request->unit ?? null,
            'sec_prop'   => $request->sec_prop ?? null,
            'category_id'=> $request->category_id ?? null,
            'purchase_price' => $request->purchase_price ?? 0,
            'old_purchase_price' => $request->old_purchase_price ?? $request->purchase_price ?? 0,
            'price'      => $request->price ?? 0,
            'old_price'  => $request->old_price ?? $request->price ?? 0,
            'barcode'    => $request->barcode,
            'status_id'  => $request->status_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $fname = $file->getClientOriginalName();
            $user_id = $request->created_by;
            $imagenewname = uniqid($user_id) . '_' . $product->id . '_' . $fname;

            $file->move(public_path('assets/img/products/'), $imagenewname);

            $product->image = 'assets/img/products/' . $imagenewname;
            $product->save();
        }

        return new ProductResource($product->fresh(['unit', 'category', 'status', 'createdBy', 'updatedBy']));
    }

    public function show(string $id)
    {
        $product = Product::with(['unit', 'category', 'status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new ProductResource($product);
    }

    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

        $request->merge([
            'barcode' => $request->barcode ?: null,
        ]);

        $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'unit_id'    => 'sometimes|exists:units,id',
            'sec_prop'   => 'nullable|string|max:255',
            'category_id'=> 'sometimes|exists:categories,id',
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'old_purchase_price' => 'sometimes|required|numeric|min:0',
            'price'      => 'sometimes|required|numeric|min:0',
            'old_price'  => 'sometimes|required|numeric|min:0',
            'barcode'    => 'nullable|string|max:255',
            'image'      => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'status_id'  => 'sometimes|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'unit_id', 'sec_prop', 'category_id', 'purchase_price', 'old_purchase_price', 'price', 'old_price', 'barcode', 'status_id', 'updated_by']);
        $user_id = $request->updated_by ?? $product->created_by;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
        
            if ($product->image && File::exists(public_path($product->image))) {
                File::delete(public_path($product->image));
            }
        
            $fname = $file->getClientOriginalName();
            $imagenewname = uniqid($user_id) . '_' . $product->id . '_' . $fname;
        
            $file->move(public_path('assets/img/products/'), $imagenewname);
            $data['image'] = 'assets/img/products/' . $imagenewname;
        }
        

        $product->update($data);

        return new ProductResource($product->fresh(['unit', 'category', 'status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            $product = Product::findOrFail($id);

            // Delete image
            if ($product->image && File::exists(public_path($product->image))) {
                File::delete(public_path($product->image));
            }

            $product->delete();

            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Product cannot be deleted'], 400);
        }
    }
}

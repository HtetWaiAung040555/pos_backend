<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PriceChangeResource;
use App\Models\PriceChange;
use App\Models\Product;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PriceChangesController extends Controller
{
    public function index()
    {
        $now = now();

        $inactiveStatus = Status::where('name', 'inactive')->value('id');
        // $activeStatus   = Status::where('name', 'active')->value('id');

        DB::transaction(function () use ($now, $inactiveStatus) {

            PriceChange::whereNull('void_at')
                ->where(function ($q) use ($now) {
                    $q->where('start_at', '>', $now)
                    ->orWhere('end_at', '<', $now);
                })
                ->update(['status_id' => $inactiveStatus]);

            // PriceChange::whereNull('void_at')
            //     ->where('start_at', '<=', $now)
            //     ->where('end_at', '>=', $now)
            //     ->update(['status_id' => $activeStatus]);
        });

        $price_changes = PriceChange::with('products')->get();
        return PriceChangeResource::collection($price_changes);
    }

    public function store(Request $request)
    {
        $request->validate([
            'description' => 'nullable|string',
            'start_at' => 'nullable|date|after_or_equal:today',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'status_id' => 'nullable|exists:statuses,id',
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.type' => 'required|in:sale,purchase',
            'products.*.new_price' => 'required|numeric|min:0',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        // Check overlaps **before transaction**
        foreach ($request->products ?? [] as $p) {
            $product = Product::with('priceChanges')->findOrFail($p['id']);

            $overlap = $product->priceChanges()
                ->where('status_id', 1) // only active
                ->where(function ($query) use ($request) {
                    if ($request->start_at && $request->end_at) {
                        $query->whereBetween('start_at', [$request->start_at, $request->end_at])
                            ->orWhereBetween('end_at', [$request->start_at, $request->end_at])
                            ->orWhere(function ($q) use ($request) {
                                $q->where('start_at', '<=', $request->start_at)
                                ->where('end_at', '>=', $request->end_at);
                            });
                    }
                })
                ->exists();

            if ($overlap) {
                return response()->json([
                    'error' => "Product {$product->name} already has a price change overlapping this period."
                ], 422);
            }
        }

        // If no overlaps, proceed with transaction
        $priceChange = DB::transaction(function () use ($request) {

            $priceChange = PriceChange::create([
                'description' => $request->description,
                'start_at' => $request->start_at,
                'end_at' => $request->end_at,
                'status_id' => $request->status_id ?? 1,
                'created_by' => $request->created_by,
                'updated_by' => $request->updated_by ?? $request->created_by
            ]);

            foreach ($request->products ?? [] as $p) {
                $product = Product::findOrFail($p['id']);
                $oldPrice = $p['type'] === 'sale' ? $product->price : $product->purchase_price;

                $priceChange->products()->attach($product->id, [
                    'type' => $p['type'],
                    'old_price' => $oldPrice,
                    'new_price' => $p['new_price'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update current product price
                if ($p['type'] === 'sale') {
                    $product->old_price = $product->price;
                    $product->price = $p['new_price'];
                } else {
                    $product->old_purchase_price = $product->purchase_price;
                    $product->purchase_price = $p['new_price'];
                }

                $product->updated_by = $request->updated_by ?? $request->created_by;
                $product->save();
            }

            return $priceChange;
        });

        $priceChange->load(['products', 'status', 'createdBy', 'updatedBy', 'voidBy']);

        return new PriceChangeResource($priceChange);
    }

    public function show(string $id)
    {
        $price_changes = PriceChange::with('products')->findOrFail($id);
        return new PriceChangeResource($price_changes);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'description' => 'nullable|string',
            'start_at' => 'nullable|date|after_or_equal:today',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'status_id' => 'nullable|exists:statuses,id',
            'products' => 'sometimes|array',
            'products.*.id' => 'required_with:products|exists:products,id',
            'products.*.type' => 'required_with:products|in:sale,purchase',
            'products.*.new_price' => 'required_with:products|numeric|min:0',
            'updated_by' => 'required|exists:users,id'
        ]);

        $priceChange = PriceChange::with('products')->findOrFail($id);
        $oldStatus = $priceChange->status_id;

        DB::transaction(function () use ($request, $priceChange, $oldStatus) {

            // Update basic attributes
            $priceChange->update([
                'description' => $request->description ?? $priceChange->description,
                'start_at'    => $request->start_at ?? $priceChange->start_at,
                'end_at'      => $request->end_at ?? $priceChange->end_at,
                'status_id'   => $request->status_id ?? $priceChange->status_id,
                'updated_by'  => $request->updated_by
            ]);

            // If changing from active to inactive, revert product prices
            if ($oldStatus == 1 && $priceChange->status_id != 1) { // assuming 1 = active
                foreach ($priceChange->products as $product) {
                    $pivot = $product->pivot;

                    if ($pivot->type === 'sale') {
                        $product->price = $pivot->old_price;
                    } else {
                        $product->purchase_price = $pivot->old_price;
                    }

                    $product->updated_by = $request->updated_by;
                    $product->save();
                }

                // Detach products from this inactive price change
                $priceChange->products()->sync([]);
            }

            // If active and products are provided, update pivot and product prices
            if ($priceChange->status_id == 1 && $request->has('products')) {
                $pivotData = [];

                foreach ($request->products as $p) {
                    $product = Product::findOrFail($p['id']);
                    $oldPrice = $p['type'] === 'sale' ? $product->price : $product->purchase_price;

                    // Prepare pivot data
                    $pivotData[$product->id] = [
                        'type' => $p['type'],
                        'old_price' => $oldPrice,
                        'new_price' => $p['new_price'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Update product prices
                    if ($p['type'] === 'sale') {
                        $product->old_price = $product->price;
                        $product->price = $p['new_price'];
                    } else {
                        $product->old_purchase_price = $product->purchase_price;
                        $product->purchase_price = $p['new_price'];
                    }

                    $product->updated_by = $request->updated_by;
                    $product->save();
                }

                $priceChange->products()->sync($pivotData);
            }
        });

        $priceChange->load(['products', 'status', 'createdBy', 'updatedBy', 'voidBy']);

        return new \App\Http\Resources\PriceChangeResource($priceChange);
    }

    public function destroy(Request $request, string $id)
    {
        $request->validate([
            'void_by' => 'required|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            $priceChange = PriceChange::with('products')->findOrFail($id);

            $voidStatus = \App\Models\Status::where('name', 'void')->firstOrFail();

            // Revert product prices before detaching
            foreach ($priceChange->products as $product) {
                $pivot = $product->pivot;

                if ($pivot->type === 'sale') {
                    $product->price = $pivot->old_price;
                } else {
                    $product->purchase_price = $pivot->old_price;
                }

                $product->updated_by = $request->void_by;
                $product->save();
            }

            // Mark price change as void
            $priceChange->status_id = $voidStatus->id;
            $priceChange->void_at   = now();
            $priceChange->void_by   = $request->void_by;
            $priceChange->save();

            // Detach products from this price change
            $priceChange->products()->sync([]);

            DB::commit();

            return response()->json([
                'message' => 'Price change voided successfully.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to void price change',
                'details' => $e->getMessage()
            ], 500);
        }
    }

}

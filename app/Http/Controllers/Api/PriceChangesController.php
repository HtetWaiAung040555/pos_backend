<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PriceChangeResource;
use App\Models\PriceChange;
use App\Models\Product;
use App\Models\Status;
use Carbon\Carbon;
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
            'type' => 'required|in:sale,purchase',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.new_price' => 'required|numeric|min:0',
        ]);

        $request->merge([
            'start_at' => $request->start_at ?: null,
            'end_at' => $request->end_at ?: null,
        ]);
        
        $start = $request->start_at ? Carbon::parse($request->start_at) : now();
        $end = $request->end_at ? Carbon::parse($request->end_at) : now();

        $conflictingProducts = [];
        foreach ($request->products as $item) {
            $product = Product::findOrFail($item['product_id']);

            $hasActive = $product->priceChanges()
                ->where('type', $request->type)
                ->where('status_id', 1)
                ->whereNull('void_at')
                ->where(function ($q) use ($start, $end) {
                    $q->where(function ($q2) use ($start, $end) {
                        $q2->whereNull('start_at')
                        ->orWhere('start_at', '<=', $end);
                    })
                    ->where(function ($q2) use ($start, $end) {
                        $q2->whereNull('end_at')
                        ->orWhere('end_at', '>=', $start);
                    });
                })
                ->exists();

            if ($hasActive) {
                $conflictingProducts[] = $product->name;
            }
        }

        // Return 422 if any product is already in a price change
        if (!empty($conflictingProducts)) {
            return response()->json([
                'errors' => [
                    'message' => 'Some products already have an active price change.',
                    'products' => $conflictingProducts
                ]
            ], 422);
        }

        // Create the price change inside transaction
        $priceChange = DB::transaction(function () use ($request) {

            $priceChange = PriceChange::create([
                'description' => $request->description,
                'type' => $request->type,
                'start_at' => $request->start_at,
                'end_at' => $request->end_at,
                'status_id' => 1,
                'created_by' => $request->created_by,
                'updated_by' => $request->updated_by ?? $request->created_by
            ]);

            // Apply price changes
            foreach ($request->products as $item) {

                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if ($priceChange->type === 'sale') {
                    $product->old_price = $product->price == 0 ? $item['new_price'] : $product->price;  
                    $product->price = $item['new_price'];
                    $oldPrice = $product->old_price;
                } else {
                    $product->old_purchase_price = $product->purchase_price == 0 ? $item['new_price'] : $product->purchase_price;
                    $product->purchase_price = $item['new_price'];
                    $oldPrice = $product->old_purchase_price;
                }

                $product->save();

                // Save history
                $priceChange->products()->attach($product->id, [
                    'old_price' => $oldPrice,
                    'new_price' => $item['new_price'],
                ]);
            }

            return $priceChange;
        });

        // Load relations for resource
        $priceChange->load([
            'status',
            'createdBy',
            'updatedBy',
            'voidBy',
            'products.unit',
            'products.category',
            'products.status',
        ]);

        // Return resource
        return new PriceChangeResource($priceChange);
    }


    public function show(string $id)
    {
        $price_changes = PriceChange::with('products')->findOrFail($id);
        return new PriceChangeResource($price_changes);
    }
    
    public function update(Request $request, string $id)
    {
        $priceChange = PriceChange::findOrFail($id);

        $request->validate([
            'description' => 'nullable|string',
            'type' => 'nullable|in:sale,purchase',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
            'products' => 'sometimes|array|min:1',
            'products.*.product_id' => 'required_with:products|exists:products,id',
            'products.*.new_price' => 'required_with:products|numeric|min:0',
            'status_id' => 'sometimes|exists:statuses,id',
            'updated_by' => 'required|exists:users,id',
        ]);

        // Merge nullable dates
        $request->merge([
            'start_at' => $request->start_at ?: null,
            'end_at' => $request->end_at ?: null,
        ]);

        $start = $request->start_at ? Carbon::parse($request->start_at) : now();
        $end = $request->end_at ? Carbon::parse($request->end_at) : now();

        // Check for conflicting products if products provided
        if ($request->has('products')) {
            $conflictingProducts = [];
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);
                $hasActive = $product->priceChanges()
                    ->where('type', $request->type ?? $priceChange->type)
                    ->where('status_id', 1)
                    ->whereNull('void_at')
                    ->where('price_changes.id', '!=', $priceChange->id)
                    ->where(function ($q) use ($start, $end) {
                        $q->where(function ($q2) use ($start, $end) {
                            $q2->whereNull('start_at')->orWhere('start_at', '<=', $end);
                        })
                        ->where(function ($q2) use ($start, $end) {
                            $q2->whereNull('end_at')->orWhere('end_at', '>=', $start);
                        });
                    })
                    ->exists();

                if ($hasActive) {
                    $conflictingProducts[] = $product->name;
                }
            }

            if (!empty($conflictingProducts)) {
                return response()->json([
                    'errors' => [
                        'message' => 'Some products already have an active price change.',
                        'products' => $conflictingProducts
                    ]
                ], 422);
            }
        }

        DB::transaction(function () use ($request, $priceChange) {
            // Update main fields
            $priceChange->update([
                'description' => $request->description ?? $priceChange->description,
                'type' => $request->type ?? $priceChange->type,
                'start_at' => $request->start_at ?? $priceChange->start_at,
                'end_at' => $request->end_at ?? $priceChange->end_at,
                'status_id' => $request->status_id ?? $priceChange->status_id,
                'updated_by' => $request->updated_by
            ]);

            // Update products if provided
            if ($request->has('products')) {
                $priceChange->products()->detach();

                foreach ($request->products as $item) {
                    $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                    if ($priceChange->type === 'sale') {
                        //$product->old_price = $product->price;
                        $product->price = $item['new_price'];
                        $oldPrice = $product->old_price;
                    } else {
                        //$product->old_purchase_price = $product->purchase_price;
                        $product->purchase_price = $item['new_price'];
                        $oldPrice = $product->old_purchase_price;
                    }

                    $product->save();

                    $priceChange->products()->attach($product->id, [
                        'old_price' => $oldPrice,
                        'new_price' => $item['new_price'],
                    ]);
                }
            }
        });

        // Reload the model and relations
        $priceChange->refresh()->load([
            'status',
            'createdBy',
            'updatedBy',
            'voidBy',
            'products.unit',
            'products.category',
            'products.status',
        ]);

        return new PriceChangeResource($priceChange);
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();
    
        try {
            $priceChange = PriceChange::with('products')->findOrFail($id);
    
            $voidStatus = \App\Models\Status::where('name', 'void')->firstOrFail();
    
            // Revert product prices before voiding
            foreach ($priceChange->products as $product) {
                if ($priceChange->type === 'sale') {
                    // Revert sale price
                    $product->price = $product->old_price;
                } else {
                    // Revert purchase price
                    $product->purchase_price = $product->old_purchase_price;
                }
                $product->save();
            }
    
            // Set void info
            $priceChange->status_id = $voidStatus->id;
            $priceChange->void_at   = now();
            $priceChange->void_by   = $request->void_by;
            $priceChange->save();
    
            // Optionally clear pivot table
            // $priceChange->products()->sync([]);
    
            DB::commit();
    
            return response()->json([
                'message' => 'Price Change voided successfully and prices reverted.'
            ], 200);
    
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'error'   => 'Failed to void price change',
                'details' => $e->getMessage()
            ], 500);
        }
    }
    
}

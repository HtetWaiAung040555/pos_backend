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
use Illuminate\Support\Facades\Log;

class PriceChangesController extends Controller
{
    /**
     * Apply started sales price changes once their start time is reached.
     *
     * Idempotency is ensured by updating only products whose current sale price
     * is still different from the pivot new_price.
     */
    public function applyStartedSalesPriceChanges(): array
    {
        $now = now();
        $activeStatusId = Status::whereRaw('LOWER(name) = ?', ['active'])->value('id');
        $appliedStatusId = Status::whereRaw('LOWER(name) = ?', ['applied'])->value('id');
        $appliedPriceChanges = 0;
        $failedPriceChanges = 0;
        $appliedProducts = 0;

        Log::info('Running sales price change check', [
            'timestamp' => $now->toDateTimeString(),
            'active_status_id' => $activeStatusId,
            'applied_status_id' => $appliedStatusId,
        ]);

        if (!$activeStatusId) {
            return [
                'applied_price_changes' => $appliedPriceChanges,
                'failed_price_changes' => $failedPriceChanges,
                'applied_products' => $appliedProducts,
                'checked_at' => $now->toDateTimeString(),
            ];
        }

        $startedSalesChanges = PriceChange::query()
            ->where('type', 'sale')
            ->whereNull('void_at')
            ->where('status_id', $activeStatusId)
            ->whereNotNull('start_at')
            ->where('start_at', '<=', $now)
            ->get();

        foreach ($startedSalesChanges as $priceChange) {
            try {
                DB::transaction(function () use ($priceChange, $activeStatusId, $appliedStatusId, &$appliedPriceChanges, &$appliedProducts) {
                    $lockedPriceChange = PriceChange::with('products')
                        ->lockForUpdate()
                        ->findOrFail($priceChange->id);

                    foreach ($lockedPriceChange->products as $linkedProduct) {
                        $product = Product::lockForUpdate()->findOrFail($linkedProduct->product_id);

                        $newSalePrice = (float) $linkedProduct->new_price;

                        // Skip already-applied values so running this method multiple times is safe.
                        if ((float) $product->price === $newSalePrice) {
                            continue;
                        }

                        if ((float) $product->old_price === 0.0) {
                            $product->old_price = $product->price;
                        }

                        $product->price = $newSalePrice;
                        $product->save();
                        $appliedProducts++;
                    }

                    if ($appliedStatusId) {
                        $lockedPriceChange->status_id = $appliedStatusId;
                    } elseif ($activeStatusId) {
                        $lockedPriceChange->status_id = $activeStatusId;
                    }

                    $lockedPriceChange->save();
                    $appliedPriceChanges++;
                });
            } catch (\Throwable $e) {
                $failedPriceChanges++;

                // Keep failed executions in Active so they can be retried.
                if ($activeStatusId) {
                    PriceChange::whereKey($priceChange->id)->update(['status_id' => $activeStatusId]);
                }
            }
        }

        return [
            'applied_price_changes' => $appliedPriceChanges,
            'failed_price_changes' => $failedPriceChanges,
            'applied_products' => $appliedProducts,
            'checked_at' => $now->toDateTimeString(),
        ];
    }

    public function runSalesPriceChangeCheck()
    {
        $result = $this->applyStartedSalesPriceChanges();

        return response()->json([
            'message' => 'Sales price change check completed.',
            'result' => $result,
        ]);
    }

    public function index(Request $request)
    {
        $this->applyStartedSalesPriceChanges();

        // $now = now();

        // $inactiveStatus = Status::where('name', 'inactive')->value('id');
        // $activeStatus   = Status::where('name', 'active')->value('id');

        // DB::transaction(function () use ($now, $inactiveStatus) {

        //     PriceChange::whereNull('void_at')
        //         ->where('start_at', '>', $now);
        //         //->update(['status_id' => $inactiveStatus]);

        //     // PriceChange::whereNull('void_at')
        //     //     ->where('start_at', '<=', $now)
        //     //     ->where('end_at', '>=', $now)
        //     //     ->update(['status_id' => $activeStatus]);
        // });

        $PriceChanges = PriceChange::with('products.product.unit', 'products.product.category', 'products.product.status')
        ->when($request->filled('type'), function ($q) use ($request) {
            $q->where('type', $request->type);
        })
        ->get();

        return PriceChangeResource::collection($PriceChanges);
    }

    public function store(Request $request)
    {
        $activeStatusId = Status::whereRaw('LOWER(name) = ?', ['active'])->value('id');

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

        // Create the price change inside transaction
        $priceChange = DB::transaction(function () use ($request, $activeStatusId) {

            $priceChange = PriceChange::create([
                'description' => $request->description,
                'type' => $request->type,
                'start_at' => $request->start_at,
                'end_at' => $request->end_at,
                'status_id' => $request->status_id ?? $activeStatusId,
                'created_by' => $request->created_by,
                'updated_by' => $request->updated_by ?? $request->created_by
            ]);

            // For sale type, only register target prices; actual price update is handled by scheduler/check method.
            foreach ($request->products as $item) {

                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if ($priceChange->type === 'sale') {
                    $oldPrice = $product->price;
                } else {
                    $product->old_purchase_price = $product->purchase_price == 0 ? $item['new_price'] : $product->purchase_price;
                    $product->purchase_price = $item['new_price'];
                    $oldPrice = $product->old_purchase_price;
                    $product->save();
                }

                // Save history
                $priceChange->products()->create([
                    'product_id' => $product->id,
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
            'products.product.unit',
            'products.product.category',
            'products.product.status',
        ]);

        // Return resource
        return new PriceChangeResource($priceChange);
    }


    public function show(string $id)
    {
        $price_changes = PriceChange::with('products.product.unit', 'products.product.category', 'products.product.status')->findOrFail($id);
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

        Log::alert('Updating Price Change', [
            'id' => $priceChange->id,
            'request' => $request->all(),
        ]);

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
                $priceChange->products()->delete();

                foreach ($request->products as $item) {
                    $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                    if ($priceChange->type === 'sale') {
                        // For sale type, keep product price unchanged; scheduler/check method applies later.
                        $oldPrice = $product->price;
                    } else {
                        $product->purchase_price = $item['new_price'];
                        $oldPrice = $product->old_purchase_price;
                        $product->save();
                    }

                    $priceChange->products()->create([
                        'product_id' => $product->id,
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
            'products.product.unit',
            'products.product.category',
            'products.product.status',
        ]);

        return new PriceChangeResource($priceChange);
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();
    
        try {
            $priceChange = PriceChange::with('products.product')->findOrFail($id);
    
            $voidStatus = \App\Models\Status::where('name', 'void')->firstOrFail();
    
            // Revert product prices before voiding
            foreach ($priceChange->products as $linkedProduct) {
                $product = Product::lockForUpdate()->findOrFail($linkedProduct->product_id);

                if ($priceChange->type === 'sale') {
                    // Revert sale price
                    $product->price = $linkedProduct->old_price;
                } else {
                    // Revert purchase price
                    $product->purchase_price = $linkedProduct->old_price;
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

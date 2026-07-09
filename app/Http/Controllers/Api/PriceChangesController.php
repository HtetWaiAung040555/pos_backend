<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PriceChangeResource;
use App\Models\BranchProduct;
use App\Models\BranchProductUnitPrice;
use App\Models\BranchProductUnitPriceRange;
use App\Models\PriceChange;
use App\Models\PriceChangeProduct;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductUnitPriceRange;
use App\Models\Status;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
            ->where(function ($query) use ($now) {
                $query->whereNull('start_at')
                    ->orWhere('start_at', '<=', $now);
            })
            ->get();

        foreach ($startedSalesChanges as $priceChange) {
            try {
                DB::transaction(function () use ($priceChange, $activeStatusId, $appliedStatusId, &$appliedPriceChanges, &$appliedProducts) {
                    $lockedPriceChange = PriceChange::with($this->priceChangeRelations())
                        ->lockForUpdate()
                        ->findOrFail($priceChange->id);

                    foreach ($lockedPriceChange->products as $linkedProduct) {
                        if ($this->applySalePriceChangeItem($linkedProduct)) {
                            $appliedProducts++;
                        }
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

        $PriceChanges = PriceChange::with($this->priceChangeRelations())
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
            'products.*.branch_id' => 'nullable|exists:branches,id',
            'products.*.branch_product_id' => 'nullable|exists:branch_products,id',
            'products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'products.*.branch_product_unit_price_id' => 'nullable|exists:branch_product_unit_prices,id',
            'products.*.product_unit_price_range_id' => 'nullable|exists:product_unit_price_ranges,id',
            'products.*.branch_product_unit_price_range_id' => 'nullable|exists:branch_product_unit_price_ranges,id',
            'products.*.min_qty' => 'nullable|numeric|min:0',
            'products.*.max_qty' => 'nullable|numeric|gt:0',
            'products.*.new_price' => 'required|numeric|min:0',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
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

                $resolvedItem = $this->resolvePriceChangeItem($item, $priceChange->type);

                if ($priceChange->type === 'purchase') {
                    $this->applyPurchasePriceChangeItem($resolvedItem);
                }

                // Save history
                $priceChange->products()->create([
                    ...$this->priceChangeItemPayload($resolvedItem),
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
            ...$this->priceChangeRelations(),
        ]);

        // Return resource
        return new PriceChangeResource($priceChange);
    }


    public function show(string $id)
    {
        $price_changes = PriceChange::with($this->priceChangeRelations())->findOrFail($id);
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
            'products.*.branch_id' => 'nullable|exists:branches,id',
            'products.*.branch_product_id' => 'nullable|exists:branch_products,id',
            'products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'products.*.branch_product_unit_price_id' => 'nullable|exists:branch_product_unit_prices,id',
            'products.*.product_unit_price_range_id' => 'nullable|exists:product_unit_price_ranges,id',
            'products.*.branch_product_unit_price_range_id' => 'nullable|exists:branch_product_unit_price_ranges,id',
            'products.*.min_qty' => 'nullable|numeric|min:0',
            'products.*.max_qty' => 'nullable|numeric|gt:0',
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
                $existingOldPrices = $priceChange->products()
                    ->get()
                    ->mapWithKeys(fn ($item) => [
                        $this->priceChangeItemKey(
                            $item->branch_id,
                            $item->product_id,
                            $item->product_unit_id,
                            $item->min_qty !== null ? (float) $item->min_qty : null,
                            $item->max_qty !== null ? (float) $item->max_qty : null,
                            $item->branch_product_unit_price_id,
                            $item->product_unit_price_range_id,
                            $item->branch_product_unit_price_range_id
                        ) => (float) $item->old_price,
                    ])
                    ->all();

                $priceChange->products()->delete();

                foreach ($request->products as $item) {
                    $resolvedItem = $this->resolvePriceChangeItem($item, $priceChange->type);
                    $itemKey = $this->priceChangeItemKey(
                        $resolvedItem['branch_id'],
                        $resolvedItem['product_id'],
                        $resolvedItem['product_unit_id'],
                        $resolvedItem['min_qty'],
                        $resolvedItem['max_qty'],
                        $resolvedItem['branch_product_unit_price_id'],
                        $resolvedItem['product_unit_price_range_id'],
                        $resolvedItem['branch_product_unit_price_range_id']
                    );

                    if (array_key_exists($itemKey, $existingOldPrices)) {
                        $resolvedItem['old_price'] = $existingOldPrices[$itemKey];
                    }

                    if ($priceChange->type === 'purchase') {
                        $this->applyPurchasePriceChangeItem($resolvedItem);
                    }

                    $priceChange->products()->create([
                        ...$this->priceChangeItemPayload($resolvedItem),
                    ]);
                }
            }
        });

        // Reload the model and relations
        $priceChange->refresh()->load($this->priceChangeRelations());

        return new PriceChangeResource($priceChange);
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();
    
        try {
            $priceChange = PriceChange::with($this->priceChangeRelations())->findOrFail($id);
    
            $voidStatus = \App\Models\Status::where('name', 'void')->firstOrFail();
    
            // Revert product prices before voiding
            foreach ($priceChange->products as $linkedProduct) {
                $this->revertPriceChangeItem($priceChange, $linkedProduct);
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

    private function priceChangeRelations(): array
    {
        return [
            'status',
            'createdBy',
            'updatedBy',
            'voidBy',
            'products.branch',
            'products.product.unit',
            'products.product.category',
            'products.product.status',
            'products.branchProduct.branch',
            'products.productUnit.unit',
            'products.branchProductUnitPrice.productUnit.unit',
            'products.branchProductUnitPrice.priceRanges',
            'products.productUnitPriceRange',
            'products.branchProductUnitPriceRange',
            'products.unit',
        ];
    }

    private function resolvePriceChangeItem(array $item, string $type): array
    {
        $product = Product::with('unit')
            ->lockForUpdate()
            ->findOrFail($item['product_id']);

        $branchProduct = $this->resolveBranchProduct($item, $product);
        $productUnit = $this->resolveProductUnit($item, $product);

        if (array_key_exists('min_qty', $item) && !$productUnit) {
            throw ValidationException::withMessages([
                'products' => 'Price range changes require product_unit_id or a range/unit price reference.',
            ]);
        }

        $branchUnitPrice = $this->resolveBranchUnitPrice($item, $branchProduct, $productUnit);
        $globalPriceRange = $this->resolveGlobalPriceRange($item, $productUnit);
        $branchPriceRange = $this->resolveBranchPriceRange($item, $branchUnitPrice);

        $newPrice = (float) $item['new_price'];

        return [
            'product' => $product,
            'branch_product' => $branchProduct,
            'product_unit' => $productUnit,
            'branch_unit_price' => $branchUnitPrice,
            'product_unit_price_range' => $globalPriceRange,
            'branch_price_range' => $branchPriceRange,
            'branch_id' => $branchProduct?->branch_id,
            'branch_product_id' => $branchProduct?->id,
            'product_id' => (int) $product->id,
            'product_unit_id' => $productUnit?->id,
            'branch_product_unit_price_id' => $branchUnitPrice?->id,
            'product_unit_price_range_id' => $globalPriceRange?->id,
            'branch_product_unit_price_range_id' => $branchPriceRange?->id,
            'unit_id' => $productUnit?->unit_id ?? $product->unit_id,
            'unit_name' => $productUnit?->unit?->name ?? $product->unit?->name,
            'conversion_to_base' => $productUnit ? (float) $productUnit->conversion_to_base : 1.0,
            'min_qty' => $this->resolveMinQty($item, $globalPriceRange, $branchPriceRange),
            'max_qty' => $this->resolveMaxQty($item, $globalPriceRange, $branchPriceRange),
            'old_price' => $this->currentItemPrice(
                $product,
                $productUnit,
                $branchProduct,
                $branchUnitPrice,
                $globalPriceRange,
                $branchPriceRange,
                $type
            ),
            'new_price' => $newPrice,
        ];
    }

    private function priceChangeItemPayload(array $item): array
    {
        return [
            'branch_id' => $item['branch_id'],
            'branch_product_id' => $item['branch_product_id'],
            'product_id' => $item['product_id'],
            'product_unit_id' => $item['product_unit_id'],
            'branch_product_unit_price_id' => $item['branch_product_unit_price_id'],
            'product_unit_price_range_id' => $item['product_unit_price_range_id'],
            'branch_product_unit_price_range_id' => $item['branch_product_unit_price_range_id'],
            'unit_id' => $item['unit_id'],
            'unit_name' => $item['unit_name'],
            'conversion_to_base' => $item['conversion_to_base'],
            'min_qty' => $item['min_qty'],
            'max_qty' => $item['max_qty'],
            'old_price' => $item['old_price'],
            'new_price' => $item['new_price'],
        ];
    }

    private function priceChangeItemKey(
        ?int $branchId,
        int $productId,
        ?int $productUnitId,
        ?float $minQty = null,
        ?float $maxQty = null,
        ?int $branchProductUnitPriceId = null,
        ?int $productUnitPriceRangeId = null,
        ?int $branchProductUnitPriceRangeId = null
    ): string
    {
        return implode(':', [
            $branchId ?? 0,
            $productId,
            $productUnitId ?? 0,
            $branchProductUnitPriceId ?? 0,
            $productUnitPriceRangeId ?? 0,
            $branchProductUnitPriceRangeId ?? 0,
            $minQty ?? 'null',
            $maxQty ?? 'null',
        ]);
    }

    private function currentItemPrice(
        Product $product,
        ?ProductUnit $productUnit,
        ?BranchProduct $branchProduct,
        ?BranchProductUnitPrice $branchUnitPrice,
        ?ProductUnitPriceRange $globalPriceRange,
        ?BranchProductUnitPriceRange $branchPriceRange,
        string $type
    ): float
    {
        if ($type === 'purchase') {
            return (float) ($productUnit?->purchase_price ?? $product->purchase_price ?? 0);
        }

        return (float) (
            $branchPriceRange?->price
            ?? $globalPriceRange?->price
            ?? $branchUnitPrice?->price
            ?? $branchProduct?->price
            ?? $productUnit?->price
            ?? $product->price
            ?? 0
        );
    }

    private function resolveBranchProduct(array $item, Product $product): ?BranchProduct
    {
        if (!empty($item['branch_product_id'])) {
            $branchProduct = BranchProduct::with('branch')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_id']);

            if ((int) $branchProduct->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Branch product {$branchProduct->id} does not belong to product {$product->id}.",
                ]);
            }

            return $branchProduct;
        }

        if (!empty($item['branch_product_unit_price_id'])) {
            $branchUnitPrice = BranchProductUnitPrice::with('branchProduct')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_unit_price_id']);

            if ((int) $branchUnitPrice->branchProduct->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Branch unit price {$branchUnitPrice->id} does not belong to product {$product->id}.",
                ]);
            }

            return $branchUnitPrice->branchProduct;
        }

        if (!empty($item['branch_product_unit_price_range_id'])) {
            $range = BranchProductUnitPriceRange::with('branchProductUnitPrice.branchProduct')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_unit_price_range_id']);

            $branchProduct = $range->branchProductUnitPrice->branchProduct;

            if ((int) $branchProduct->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Branch price range {$range->id} does not belong to product {$product->id}.",
                ]);
            }

            return $branchProduct;
        }

        if (!empty($item['branch_id'])) {
            return BranchProduct::where('branch_id', $item['branch_id'])
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    private function resolveProductUnit(array $item, Product $product): ?ProductUnit
    {
        if (!empty($item['product_unit_id'])) {
            $productUnit = ProductUnit::with('unit')
                ->lockForUpdate()
                ->findOrFail($item['product_unit_id']);

            if ((int) $productUnit->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Product unit {$productUnit->id} does not belong to product {$product->id}.",
                ]);
            }

            return $productUnit;
        }

        if (!empty($item['branch_product_unit_price_id'])) {
            $branchUnitPrice = BranchProductUnitPrice::with('productUnit.unit', 'branchProduct')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_unit_price_id']);

            if ((int) $branchUnitPrice->branchProduct->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Branch unit price {$branchUnitPrice->id} does not belong to product {$product->id}.",
                ]);
            }

            return $branchUnitPrice->productUnit;
        }

        if (!empty($item['product_unit_price_range_id'])) {
            $range = ProductUnitPriceRange::with('productUnit.unit')
                ->lockForUpdate()
                ->findOrFail($item['product_unit_price_range_id']);

            if ((int) $range->productUnit->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Product unit price range {$range->id} does not belong to product {$product->id}.",
                ]);
            }

            return $range->productUnit;
        }

        if (!empty($item['branch_product_unit_price_range_id'])) {
            $range = BranchProductUnitPriceRange::with('branchProductUnitPrice.productUnit.unit', 'branchProductUnitPrice.branchProduct')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_unit_price_range_id']);

            if ((int) $range->branchProductUnitPrice->branchProduct->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Branch price range {$range->id} does not belong to product {$product->id}.",
                ]);
            }

            return $range->branchProductUnitPrice->productUnit;
        }

        return null;
    }

    private function resolveBranchUnitPrice(
        array $item,
        ?BranchProduct $branchProduct,
        ?ProductUnit $productUnit
    ): ?BranchProductUnitPrice {
        if (!empty($item['branch_product_unit_price_id'])) {
            return BranchProductUnitPrice::with('productUnit.unit', 'branchProduct')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_unit_price_id']);
        }

        if (!empty($item['branch_product_unit_price_range_id'])) {
            return BranchProductUnitPriceRange::with('branchProductUnitPrice.productUnit.unit', 'branchProductUnitPrice.branchProduct')
                ->lockForUpdate()
                ->findOrFail($item['branch_product_unit_price_range_id'])
                ->branchProductUnitPrice;
        }

        if ($branchProduct && $productUnit) {
            return BranchProductUnitPrice::where('branch_product_id', $branchProduct->id)
                ->where('product_unit_id', $productUnit->id)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    private function resolveGlobalPriceRange(array $item, ?ProductUnit $productUnit): ?ProductUnitPriceRange
    {
        if (!empty($item['product_unit_price_range_id'])) {
            return ProductUnitPriceRange::lockForUpdate()->findOrFail($item['product_unit_price_range_id']);
        }

        if ($productUnit && empty($item['branch_id']) && array_key_exists('min_qty', $item)) {
            return ProductUnitPriceRange::where('product_unit_id', $productUnit->id)
                ->where('min_qty', $item['min_qty'])
                ->where(function ($query) use ($item) {
                    if (array_key_exists('max_qty', $item) && $item['max_qty'] !== null) {
                        $query->where('max_qty', $item['max_qty']);
                    } else {
                        $query->whereNull('max_qty');
                    }
                })
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    private function resolveBranchPriceRange(
        array $item,
        ?BranchProductUnitPrice $branchUnitPrice
    ): ?BranchProductUnitPriceRange {
        if (!empty($item['branch_product_unit_price_range_id'])) {
            return BranchProductUnitPriceRange::lockForUpdate()->findOrFail($item['branch_product_unit_price_range_id']);
        }

        if ($branchUnitPrice && array_key_exists('min_qty', $item)) {
            return BranchProductUnitPriceRange::where('branch_product_unit_price_id', $branchUnitPrice->id)
                ->where('min_qty', $item['min_qty'])
                ->where(function ($query) use ($item) {
                    if (array_key_exists('max_qty', $item) && $item['max_qty'] !== null) {
                        $query->where('max_qty', $item['max_qty']);
                    } else {
                        $query->whereNull('max_qty');
                    }
                })
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    private function resolveMinQty(
        array $item,
        ?ProductUnitPriceRange $globalPriceRange,
        ?BranchProductUnitPriceRange $branchPriceRange
    ): ?float {
        if (array_key_exists('min_qty', $item)) {
            return (float) $item['min_qty'];
        }

        return $branchPriceRange?->min_qty !== null
            ? (float) $branchPriceRange->min_qty
            : ($globalPriceRange?->min_qty !== null ? (float) $globalPriceRange->min_qty : null);
    }

    private function resolveMaxQty(
        array $item,
        ?ProductUnitPriceRange $globalPriceRange,
        ?BranchProductUnitPriceRange $branchPriceRange
    ): ?float {
        if (array_key_exists('max_qty', $item)) {
            return $item['max_qty'] !== null ? (float) $item['max_qty'] : null;
        }

        return $branchPriceRange?->max_qty !== null
            ? (float) $branchPriceRange->max_qty
            : ($globalPriceRange?->max_qty !== null ? (float) $globalPriceRange->max_qty : null);
    }

    private function applySalePriceChangeItem(PriceChangeProduct $linkedProduct): bool
    {
        $newSalePrice = (float) $linkedProduct->new_price;

        if ($linkedProduct->branch_id && $linkedProduct->product_unit_id && $linkedProduct->min_qty !== null) {
            $range = $this->ensureBranchPriceRange($linkedProduct);

            if ((float) $range->price === $newSalePrice) {
                return false;
            }

            $range->old_price = $linkedProduct->old_price;
            $range->price = $newSalePrice;
            $range->save();

            $range->loadMissing('branchProductUnitPrice.branchProduct');

            $this->syncAppliedTargetIds(
                $linkedProduct,
                $range->branchProductUnitPrice->branchProduct,
                $range->branchProductUnitPrice,
                null,
                $range
            );

            return true;
        }

        if (!$linkedProduct->branch_id && $linkedProduct->product_unit_id && $linkedProduct->min_qty !== null) {
            $range = $this->ensureGlobalPriceRange($linkedProduct);

            if ((float) $range->price === $newSalePrice) {
                return false;
            }

            $range->old_price = $linkedProduct->old_price;
            $range->price = $newSalePrice;
            $range->save();

            $this->syncAppliedTargetIds($linkedProduct, null, null, $range, null);

            return true;
        }

        if ($linkedProduct->branch_id && $linkedProduct->product_unit_id) {
            $branchUnitPrice = $this->ensureBranchUnitPrice($linkedProduct);

            if ((float) $branchUnitPrice->price === $newSalePrice) {
                return false;
            }

            $branchUnitPrice->old_price = $linkedProduct->old_price;
            $branchUnitPrice->price = $newSalePrice;
            $branchUnitPrice->save();

            $this->syncAppliedTargetIds($linkedProduct, $branchUnitPrice->branchProduct, $branchUnitPrice, null, null);

            return true;
        }

        if ($linkedProduct->branch_id) {
            $branchProduct = $this->ensureBranchProduct($linkedProduct);

            if ((float) $branchProduct->price === $newSalePrice) {
                return false;
            }

            $branchProduct->old_price = $linkedProduct->old_price;
            $branchProduct->price = $newSalePrice;
            $branchProduct->save();

            $this->syncAppliedTargetIds($linkedProduct, $branchProduct, null, null, null);

            return true;
        }

        if ($linkedProduct->product_unit_id) {
            $productUnit = ProductUnit::lockForUpdate()->findOrFail($linkedProduct->product_unit_id);

            if ((float) $productUnit->price === $newSalePrice) {
                return false;
            }

            if ((float) $productUnit->old_price === 0.0) {
                $productUnit->old_price = $productUnit->price;
            }

            $productUnit->price = $newSalePrice;
            $productUnit->save();

            return true;
        }

        $product = Product::lockForUpdate()->findOrFail($linkedProduct->product_id);

        if ((float) $product->price === $newSalePrice) {
            return false;
        }

        if ((float) $product->old_price === 0.0) {
            $product->old_price = $product->price;
        }

        $product->price = $newSalePrice;
        $product->save();

        return true;
    }

    private function applyPurchasePriceChangeItem(array $item): void
    {
        $product = $item['product'];
        $productUnit = $item['product_unit'];
        $newPrice = (float) $item['new_price'];

        if ($productUnit) {
            $productUnit->old_purchase_price = $productUnit->purchase_price == 0
                ? $newPrice
                : $productUnit->purchase_price;
            $productUnit->purchase_price = $newPrice;
            $productUnit->save();

            return;
        }

        $product->old_purchase_price = $product->purchase_price == 0
            ? $newPrice
            : $product->purchase_price;
        $product->purchase_price = $newPrice;
        $product->save();
    }

    private function ensureBranchProduct(PriceChangeProduct $linkedProduct): BranchProduct
    {
        if ($linkedProduct->branch_product_id) {
            return BranchProduct::lockForUpdate()->findOrFail($linkedProduct->branch_product_id);
        }

        $branchProduct = BranchProduct::firstOrNew([
            'branch_id' => $linkedProduct->branch_id,
            'product_id' => $linkedProduct->product_id,
        ]);

        if (!$branchProduct->exists) {
            $branchProduct->price = $linkedProduct->old_price;
            $branchProduct->old_price = $linkedProduct->old_price;
        }

        $branchProduct->save();

        return $branchProduct;
    }

    private function ensureBranchUnitPrice(PriceChangeProduct $linkedProduct): BranchProductUnitPrice
    {
        if ($linkedProduct->branch_product_unit_price_id) {
            return BranchProductUnitPrice::with('branchProduct')
                ->lockForUpdate()
                ->findOrFail($linkedProduct->branch_product_unit_price_id);
        }

        $branchProduct = $this->ensureBranchProduct($linkedProduct);
        $productUnit = ProductUnit::with('unit')->findOrFail($linkedProduct->product_unit_id);

        $branchUnitPrice = BranchProductUnitPrice::firstOrNew([
            'branch_product_id' => $branchProduct->id,
            'product_unit_id' => $productUnit->id,
        ]);

        if (!$branchUnitPrice->exists) {
            $branchUnitPrice->unit_id = $productUnit->unit_id;
            $branchUnitPrice->unit_name = $productUnit->unit->name ?? $linkedProduct->unit_name;
            $branchUnitPrice->conversion_to_base = $productUnit->conversion_to_base;
            $branchUnitPrice->price = $linkedProduct->old_price;
            $branchUnitPrice->old_price = $linkedProduct->old_price;
            $branchUnitPrice->status_id = $branchProduct->status_id;
        }

        $branchUnitPrice->save();

        return $branchUnitPrice;
    }

    private function ensureGlobalPriceRange(PriceChangeProduct $linkedProduct): ProductUnitPriceRange
    {
        if ($linkedProduct->product_unit_price_range_id) {
            return ProductUnitPriceRange::lockForUpdate()->findOrFail($linkedProduct->product_unit_price_range_id);
        }

        $productUnit = ProductUnit::lockForUpdate()->findOrFail($linkedProduct->product_unit_id);

        $range = ProductUnitPriceRange::firstOrNew([
            'product_unit_id' => $productUnit->id,
            'min_qty' => $linkedProduct->min_qty,
            'max_qty' => $linkedProduct->max_qty,
        ]);

        if (!$range->exists) {
            $range->price = $linkedProduct->old_price;
            $range->old_price = $linkedProduct->old_price;
            $range->status_id = $productUnit->status_id;
            $range->created_by = $productUnit->created_by;
            $range->updated_by = $productUnit->updated_by;
        }

        $range->save();

        return $range;
    }

    private function ensureBranchPriceRange(PriceChangeProduct $linkedProduct): BranchProductUnitPriceRange
    {
        if ($linkedProduct->branch_product_unit_price_range_id) {
            return BranchProductUnitPriceRange::lockForUpdate()
                ->findOrFail($linkedProduct->branch_product_unit_price_range_id);
        }

        $branchUnitPrice = $this->ensureBranchUnitPrice($linkedProduct);

        $range = BranchProductUnitPriceRange::firstOrNew([
            'branch_product_unit_price_id' => $branchUnitPrice->id,
            'min_qty' => $linkedProduct->min_qty,
            'max_qty' => $linkedProduct->max_qty,
        ]);

        if (!$range->exists) {
            $range->price = $linkedProduct->old_price;
            $range->old_price = $linkedProduct->old_price;
            $range->status_id = $branchUnitPrice->status_id;
            $range->created_by = $branchUnitPrice->created_by;
            $range->updated_by = $branchUnitPrice->updated_by;
        }

        $range->save();

        return $range;
    }

    private function syncAppliedTargetIds(
        PriceChangeProduct $linkedProduct,
        ?BranchProduct $branchProduct,
        ?BranchProductUnitPrice $branchUnitPrice,
        ?ProductUnitPriceRange $globalRange,
        ?BranchProductUnitPriceRange $branchRange
    ): void {
        $linkedProduct->forceFill([
            'branch_product_id' => $branchProduct?->id ?? $linkedProduct->branch_product_id,
            'branch_product_unit_price_id' => $branchUnitPrice?->id ?? $linkedProduct->branch_product_unit_price_id,
            'product_unit_price_range_id' => $globalRange?->id ?? $linkedProduct->product_unit_price_range_id,
            'branch_product_unit_price_range_id' => $branchRange?->id ?? $linkedProduct->branch_product_unit_price_range_id,
        ])->save();
    }

    private function revertPriceChangeItem(PriceChange $priceChange, PriceChangeProduct $linkedProduct): void
    {
        if ($priceChange->type === 'sale' && $linkedProduct->branch_product_unit_price_range_id) {
            $range = BranchProductUnitPriceRange::lockForUpdate()
                ->findOrFail($linkedProduct->branch_product_unit_price_range_id);
            $range->price = $linkedProduct->old_price;
            $range->save();

            return;
        }

        if ($priceChange->type === 'sale' && $linkedProduct->product_unit_price_range_id) {
            $range = ProductUnitPriceRange::lockForUpdate()
                ->findOrFail($linkedProduct->product_unit_price_range_id);
            $range->price = $linkedProduct->old_price;
            $range->save();

            return;
        }

        if ($priceChange->type === 'sale' && $linkedProduct->branch_product_unit_price_id) {
            $branchUnitPrice = BranchProductUnitPrice::lockForUpdate()
                ->findOrFail($linkedProduct->branch_product_unit_price_id);
            $branchUnitPrice->price = $linkedProduct->old_price;
            $branchUnitPrice->save();

            return;
        }

        if ($priceChange->type === 'sale' && $linkedProduct->branch_product_id) {
            $branchProduct = BranchProduct::lockForUpdate()
                ->findOrFail($linkedProduct->branch_product_id);
            $branchProduct->price = $linkedProduct->old_price;
            $branchProduct->save();

            return;
        }

        if ($priceChange->type === 'sale' && $linkedProduct->branch_id) {
            return;
        }

        if ($linkedProduct->product_unit_id) {
            $productUnit = ProductUnit::lockForUpdate()->findOrFail($linkedProduct->product_unit_id);

            if ($priceChange->type === 'sale') {
                $productUnit->price = $linkedProduct->old_price;
            } else {
                $productUnit->purchase_price = $linkedProduct->old_price;
            }

            $productUnit->save();

            return;
        }

        $product = Product::lockForUpdate()->findOrFail($linkedProduct->product_id);

        if ($priceChange->type === 'sale') {
            $product->price = $linkedProduct->old_price;
        } else {
            $product->purchase_price = $linkedProduct->old_price;
        }

        $product->save();
    }
     
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Symfony\Component\Clock\now;

class PurchasesController extends Controller
{

    public function index(Request $request)
    {
        $query = PurchasesController::build($request)
            ->select('purchases.*')
            ->with([
                'supplier',
                'status',
                'warehouse',
                'paymentMethod',
                'details.product',
                'details.productUnit.unit',
                'details.unit',
                'createdBy',
                'updatedBy'
            ])
            ->orderByDesc('purchases.purchase_date');

        $perPage = $request->get('per_page', 50);

        $purchases = $query->paginate($perPage);

        return PurchaseResource::collection($purchases);
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'payment_id' => 'required|exists:payment_methods,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'status_id' => 'required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'purchase_date' => 'nullable|date',
            'warehouse_id' => 'required|exists:warehouses,id',
            'created_by' => 'required|exists:users,id',

            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'products.*.quantity' => 'required|numeric|gt:0',
            'products.*.purchase_price' => 'nullable|numeric|min:0',
            'products.*.expired_date' => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            $resolvedItems = collect($request->products)
                ->map(fn ($item) => $this->resolvePurchaseItem($item));

            foreach ($resolvedItems as $item) {
                $this->updatePurchasePrice($item);
            }

            $totalAmount = $resolvedItems->sum(
                fn ($item) => $item['purchase_price'] * $item['unit_quantity']
            );

            // Create Purchase
            $purchase = Purchase::create([
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'total_amount' => $totalAmount,
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark,
                'purchase_date' => $request->purchase_date ?? now(),
                'created_by' => $request->created_by,
                'updated_by' => $request->created_by,
            ]);

            foreach ($resolvedItems as $item) {
                $this->receivePurchaseItem(
                    $purchase,
                    $item,
                    (int) $request->warehouse_id,
                    (int) $request->created_by,
                    $request->purchase_date ?? now()
                );
            }

            DB::commit();

            return new PurchaseResource(
                $purchase->fresh([
                    'supplier',
                    'warehouse',
                    'status',
                    'paymentMethod',
                    'details.product',
                    'details.productUnit.unit',
                    'details.unit',
                    'createdBy',
                    'updatedBy'
                ])
            );

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to create purchase',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $purchase = Purchase::with([
            'supplier',
            'status',
            'paymentMethod',
            'details.product',
            'details.productUnit.unit',
            'details.unit',
            'createdBy',
            'updatedBy'
        ])->findOrFail($id);

        return new PurchaseResource($purchase);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_id' => 'sometimes|required|exists:payment_methods,id',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'purchase_date' => 'sometimes|date',
            'updated_by' => 'required|exists:users,id',
            'products' => 'nullable|array|min:1',
            'products.*.product_id' => 'required_with:products|exists:products,id',
            'products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'products.*.quantity' => 'required_with:products|numeric|gt:0',
            'products.*.purchase_price' => 'nullable|numeric|min:0',
            'products.*.expired_date' => 'nullable|date',
        ]);

        $purchase = Purchase::findOrFail($id);

        DB::beginTransaction();

        try {

            $totalAmount = 0;

            if ($request->has('products')) {

                foreach ($purchase->details as $detail) {

                    $inventory = Inventory::lockForUpdate()->find($detail->inventory_id);

                    if (!$inventory) {
                        throw new \Exception("Inventory not found for rollback");
                    }

                    $inventory->qty -= (int) ($detail->base_quantity ?? $detail->quantity);
                    $inventory->updated_by = $request->updated_by;
                    $inventory->save();

                }

                PurchaseDetail::where("purchase_id", $purchase->id)->delete();

                StockTransaction::where('reference_id', $purchase->id)
                    ->delete();

                $resolvedItems = collect($request->products)
                    ->map(fn ($item) => $this->resolvePurchaseItem($item));

                foreach ($resolvedItems as $item) {
                    $this->updatePurchasePrice($item);

                    $this->receivePurchaseItem(
                        $purchase,
                        $item,
                        (int) $purchase->warehouse_id,
                        (int) $request->updated_by,
                        $request->purchase_date ?? $purchase->purchase_date
                    );
                }

                $totalAmount = $resolvedItems->sum(
                    fn ($item) => $item['purchase_price'] * $item['unit_quantity']
                );
            } else {
                $totalAmount = $purchase->total_amount;
            }

            // Update purchase header
            $purchase->update([
                'payment_id'    => $request->payment_id ?? $purchase->payment_id,
                'status_id'     => $request->status_id ?? $purchase->status_id,
                'remark'        => $request->remark ?? $purchase->remark,
                'total_amount' => $totalAmount,
                'purchase_date' => $request->purchase_date ?? $purchase->purchase_date,
                'updated_by'    => $request->updated_by,
            ]);

            DB::commit();

            return new PurchaseResource(
                $purchase->fresh([
                    'supplier',
                    'warehouse',
                    'status',
                    'paymentMethod',
                    'details.product',
                    'details.productUnit.unit',
                    'details.unit',
                    'createdBy',
                    'updatedBy'
                ])
            );

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to update purchase',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        $request->validate([
            'void_by' => 'required|exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            $purchase = Purchase::with('details')->findOrFail($id);
            $voidStatus = \App\Models\Status::where('name', 'void')->first();

            // Void purchase
            $purchase->update([
                'status_id' => $voidStatus->id,
                'void_at' => now(),
                'void_by' => $request->void_by,
            ]);

            // Rollback stock
            foreach ($purchase->details as $detail) {

                $inventory = Inventory::find($detail->inventory_id);

                if (!$inventory) {
                    throw new \RuntimeException("Inventory not found for purchase detail {$detail->id}");
                }

                $hasOutTransaction = StockTransaction::where('inventory_id', $inventory->id)
                    ->where('reference_type', 'sale')
                    ->exists();

                if ($hasOutTransaction) {
                    throw ValidationException::withMessages([
                        'purchase' => 'This purchase was already used. Quantity cannot be directly updated. Please use stock adjustment.',
                    ]);
                }

                $baseQuantity = (int) ($detail->base_quantity ?? $detail->quantity);

                $inventory->qty -= $baseQuantity;
                $inventory->save();

                StockTransaction::create([
                    'inventory_id'    => $inventory->id,
                    'product_unit_id' => $detail->product_unit_id,
                    'unit_id'         => $detail->unit_id,
                    'reference_id'    => $purchase->id,
                    'reference_type'  => 'purchase_void',
                    'reference_date' => $purchase->purchase_date,
                    'quantity_change' => $baseQuantity,
                    'unit_quantity'   => $detail->unit_quantity,
                    'base_quantity'   => $baseQuantity,
                    'conversion_to_base' => $detail->conversion_to_base,
                    'type'            => 'out',
                    'created_by'      => $request->void_by,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Purchase voided successfully.'
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to void purchase',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function resolvePurchaseItem(array $item): array
    {
        $product = Product::with('unit')->findOrFail($item['product_id']);
        $productUnit = null;

        if (!empty($item['product_unit_id'])) {
            $productUnit = ProductUnit::with('unit')->findOrFail($item['product_unit_id']);

            if ((int) $productUnit->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'products' => "Product unit {$productUnit->id} does not belong to product {$product->id}.",
                ]);
            }
        }

        $conversion = $productUnit ? (float) $productUnit->conversion_to_base : 1.0;
        $unitQuantity = (float) $item['quantity'];
        $calculatedBaseQuantity = $unitQuantity * $conversion;
        $baseQuantity = (int) round($calculatedBaseQuantity);

        if (abs($calculatedBaseQuantity - $baseQuantity) > 0.000001) {
            throw ValidationException::withMessages([
                'products' => "Product {$product->id} converts to a fractional base quantity, but inventory currently stores whole quantities.",
            ]);
        }

        $purchasePrice = array_key_exists('purchase_price', $item) && !is_null($item['purchase_price'])
            ? (float) $item['purchase_price']
            : (float) ($productUnit?->purchase_price ?? $product->purchase_price ?? 0);

        return [
            'product' => $product,
            'product_unit' => $productUnit,
            'product_id' => (int) $product->id,
            'product_unit_id' => $productUnit?->id,
            'unit_id' => $productUnit?->unit_id ?? $product->unit_id,
            'unit_name' => $productUnit?->unit?->name ?? $product->unit?->name,
            'unit_barcode' => $productUnit?->barcode ?? $product->barcode,
            'conversion_to_base' => $conversion,
            'unit_quantity' => $unitQuantity,
            'base_quantity' => $baseQuantity,
            'purchase_price' => $purchasePrice,
            'purchase_price_provided' => array_key_exists('purchase_price', $item),
            'expired_date' => $item['expired_date'] ?? null,
        ];
    }

    private function updatePurchasePrice(array $item): void
    {
        if (!$item['purchase_price_provided']) {
            return;
        }

        $price = $item['purchase_price'];
        $product = $item['product'];
        $productUnit = $item['product_unit'];

        if ($productUnit && (float) $productUnit->purchase_price !== $price) {
            $productUnit->old_purchase_price = $productUnit->purchase_price;
            $productUnit->purchase_price = $price;
            $productUnit->save();
        }

        if (!$productUnit || $productUnit->is_base_unit) {
            if ((float) $product->purchase_price !== $price) {
                $product->old_purchase_price = $product->purchase_price;
                $product->purchase_price = $price;
                $product->save();
            }
        }
    }

    private function receivePurchaseItem(
        Purchase $purchase,
        array $item,
        int $warehouseId,
        int $userId,
        $referenceDate
    ): void {
        $remainingBaseQuantity = (int) $item['base_quantity'];

        $negativeInventories = Inventory::where('product_id', $item['product_id'])
            ->where('warehouse_id', $warehouseId)
            ->where('qty', '<', 0)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($negativeInventories as $inventory) {
            if ($remainingBaseQuantity <= 0) {
                break;
            }

            $receivedBaseQuantity = min(abs((int) $inventory->qty), $remainingBaseQuantity);

            $inventory->qty += $receivedBaseQuantity;
            $inventory->expired_date = $item['expired_date'];
            $inventory->updated_by = $userId;
            $inventory->save();

            $this->recordPurchaseReceipt(
                $purchase,
                $inventory,
                $item,
                $receivedBaseQuantity,
                $userId,
                $referenceDate
            );

            $remainingBaseQuantity -= $receivedBaseQuantity;
        }

        if ($remainingBaseQuantity <= 0) {
            return;
        }

        $inventory = Inventory::where('product_id', $item['product_id'])
            ->where('warehouse_id', $warehouseId)
            ->where('expired_date', $item['expired_date'])
            ->lockForUpdate()
            ->first();

        if ($inventory) {
            $inventory->qty += $remainingBaseQuantity;
            $inventory->updated_by = $userId;
            $inventory->save();
        } else {
            $inventory = Inventory::create([
                'product_id' => $item['product_id'],
                'warehouse_id' => $warehouseId,
                'expired_date' => $item['expired_date'],
                'qty' => $remainingBaseQuantity,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        $this->recordPurchaseReceipt(
            $purchase,
            $inventory,
            $item,
            $remainingBaseQuantity,
            $userId,
            $referenceDate
        );
    }

    private function recordPurchaseReceipt(
        Purchase $purchase,
        Inventory $inventory,
        array $item,
        int $baseQuantity,
        int $userId,
        $referenceDate
    ): void {
        $unitQuantity = $baseQuantity / $item['conversion_to_base'];

        StockTransaction::create([
            'inventory_id' => $inventory->id,
            'product_unit_id' => $item['product_unit_id'],
            'unit_id' => $item['unit_id'],
            'reference_id' => $purchase->id,
            'reference_type' => 'purchase',
            'reference_date' => $referenceDate,
            'quantity_change' => $baseQuantity,
            'unit_quantity' => $unitQuantity,
            'base_quantity' => $baseQuantity,
            'conversion_to_base' => $item['conversion_to_base'],
            'type' => 'in',
            'created_by' => $userId,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'inventory_id' => $inventory->id,
            'product_id' => $item['product_id'],
            'product_unit_id' => $item['product_unit_id'],
            'unit_id' => $item['unit_id'],
            'unit_name' => $item['unit_name'],
            'unit_quantity' => $unitQuantity,
            'base_quantity' => $baseQuantity,
            'conversion_to_base' => $item['conversion_to_base'],
            'unit_barcode' => $item['unit_barcode'],
            'price_range_id' => null,
            'quantity' => $baseQuantity,
            'price' => $item['purchase_price'],
            'total' => round($item['purchase_price'] * $unitQuantity, 2),
        ]);
    }

    public static function build(Request $request)
    {
        return Purchase::query()
            ->when($request->filled('purchase_id'), function ($q) use ($request) {
                $q->where('purchases.id', $request->purchase_id);
            })

            ->when($request->filled('supplier_search'), function ($q) use ($request) {

                $keyword = $request->supplier_search;

                $q->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('suppliers.id', $keyword)
                          ->orWhere('suppliers.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('status_id'), function ($q) use ($request) {
                $q->where('purchases.status_id', $request->status_id);
            })

            ->when($request->filled('payment_id'), function ($q) use ($request) {
                $q->where('purchases.payment_id', $request->payment_id);
            })

            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                $q->where('purchases.warehouse_id', $request->warehouse_id);
            })

            ->when($request->filled('product_search'), function ($q) use ($request) {

                $keyword = $request->product_search;

                $q->join('purchase_details', 'purchases.id', '=', 'purchase_details.purchase_id')
                  ->join('products', 'purchase_details.product_id', '=', 'products.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('products.barcode', $keyword)
                          ->orWhereExists(function ($unitQuery) use ($keyword) {
                              $unitQuery->select(DB::raw(1))
                                  ->from('product_units')
                                  ->whereColumn('product_units.id', 'purchase_details.product_unit_id')
                                  ->where('product_units.barcode', $keyword);
                          })
                          ->orWhere('products.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('start_date'), function ($q) use ($request) {
                $q->whereDate('purchases.purchase_date', '>=', $request->start_date);
            })

            ->when($request->filled('end_date'), function ($q) use ($request) {
                $q->whereDate('purchases.purchase_date', '<=', $request->end_date);
            })

            ->distinct();
    }

    public function export(Request $request)
    {
        $purchases = PurchasesController::build($request)
            ->select('purchases.*')
            ->with([
                'supplier',
                'details.product',
                'details.productUnit.unit',
                'details.unit',
            ])
            ->orderByDesc('purchases.purchase_date')
            ->get();

        return PurchaseResource::collection($purchases);
    }

    public function dashboard(Request $request)
    {
        $query = PurchasesController::build($request);

        $stats = (clone $query)
            ->selectRaw("
                COUNT(DISTINCT purchases.id) as total_invoice,
                COALESCE(SUM(purchases.total_amount),0) as total_purchases,
                COALESCE(SUM(CASE WHEN purchases.payment_id = 1 THEN purchases.total_amount END),0) as total_cash,
                COALESCE(SUM(CASE WHEN purchases.payment_id = 4 THEN purchases.total_amount END),0) as total_kpay,
                COALESCE(SUM(CASE WHEN purchases.payment_id = 2 THEN purchases.total_amount END),0) as total_credit
            ")
            ->first();

        return response()->json($stats);
    }
    
}

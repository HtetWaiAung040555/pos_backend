<?php

namespace App\Http\Controllers\Api;
use App\Models\BranchProduct;
use App\Models\BranchProductUnitPrice;
use App\Models\Product;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Branch;
use App\Models\ProductUnit;
use App\Services\SellingPriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductsController extends Controller
{
    public function index()
    {
        $products = Product::with($this->productRelations())->get();
        return ProductResource::collection($products);
    }

    public function store(Request $request)
    {
        $this->normalizeProductUnitsInput($request);
        $this->normalizeBranchProductsInput($request);

        $request->merge([
            'unit_id' => $request->unit_id ?: null,
            'barcode' => $request->barcode ?: null,
            'category_id' => $request->category_id ?: null,
        ]);

        $validated = $request->validate($this->productRules($request));

        $product = DB::transaction(function () use ($request, $validated) {
            $product = Product::create([
                'name'       => $request->name,
                'unit_id'    => $request->unit_id ?? null,
                'sec_prop'   => $request->sec_prop ?? null,
                'category_id'=> $request->category_id ?? null,
                'purchase_price' => $request->purchase_price ?? 0,
                'old_purchase_price' => $request->old_purchase_price ?? $request->purchase_price ?? 0,
                'price'      => $request->price ?? 0,
                'old_price'  => $request->old_price ?? $request->price ?? 0,
                'barcode'    => $request->barcode,
                'uom_enabled' => $request->boolean('uom_enabled'),
                'status_id'  => $request->status_id,
                'created_by' => $request->created_by,
                'updated_by' => $request->updated_by ?? $request->created_by
            ]);

            $this->syncProductUnits(
                $product,
                $validated['product_units'] ?? [],
                (int) $request->created_by,
                (int) ($request->updated_by ?? $request->created_by)
            );

            $this->syncDefaultProductUnit($product, $request->default_product_unit_id);

            $this->syncBranchProducts(
                $product,
                $validated['branch_products'] ?? [],
                (int) $request->created_by,
                (int) ($request->updated_by ?? $request->created_by)
            );

            return $product;
        });

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

        return new ProductResource($product->fresh($this->productRelations()));
    }

    public function show(string $id)
    {
        $product = Product::with($this->productRelations())->findOrFail($id);
        return new ProductResource($product);
    }

    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);
        $this->normalizeProductUnitsInput($request);
        $this->normalizeBranchProductsInput($request);

        $request->merge([
            'barcode' => $request->barcode ?: null,
        ]);

        $validated = $request->validate($this->productRules($request, $product));

        $data = $request->only([
            'name',
            'unit_id',
            'sec_prop',
            'category_id',
            'purchase_price',
            'old_purchase_price',
            'price',
            'old_price',
            'barcode',
            'status_id',
            'updated_by',
            'uom_enabled',
        ]);

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

        DB::transaction(function () use ($product, $data, $request, $validated, $user_id) {
            $product->update($data);

            $this->syncProductUnits(
                $product,
                $validated['product_units'] ?? [],
                (int) $product->created_by,
                (int) $user_id
            );

            $this->syncDefaultProductUnit($product, $request->default_product_unit_id);

            $this->syncBranchProducts(
                $product,
                $validated['branch_products'] ?? [],
                (int) $product->created_by,
                (int) $user_id
            );
        });

        return new ProductResource($product->fresh($this->productRelations()));
    }

    public function destroy(string $id)
    {
        try {
            $product = Product::findOrFail($id);
            $blockers = $this->productDeleteBlockers((int) $product->id);

            if (!empty($blockers)) {
                return response()->json([
                    'error' => 'Product cannot be deleted because it is already used.',
                    'blockers' => $blockers,
                ], 422);
            }

            $imagePath = $product->image;

            DB::transaction(function () use ($product) {
                $lockedProduct = Product::with([
                    'branchProducts.unitPrices.priceRanges',
                    'productUnits.priceRanges',
                ])
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                foreach ($lockedProduct->branchProducts as $branchProduct) {
                    foreach ($branchProduct->unitPrices as $unitPrice) {
                        $unitPrice->priceRanges()->delete();
                    }

                    $branchProduct->unitPrices()->delete();
                }

                $lockedProduct->branchProducts()->delete();

                foreach ($lockedProduct->productUnits as $productUnit) {
                    $productUnit->priceRanges()->delete();
                    $productUnit->delete();
                }

                $lockedProduct->delete();
            });

            if ($imagePath && File::exists(public_path($imagePath))) {
                File::delete(public_path($imagePath));
            }

            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Product cannot be deleted',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function lastCustomBarcode(Request $request)
    {
        $prefix = $request->get('prefix', 'KBAM');
        $like = $prefix . '-%';

        $productBarcodes = Product::whereNotNull('barcode')
            ->where('barcode', 'like', $like)
            ->pluck('barcode');

        $productUnitBarcodes = ProductUnit::whereNotNull('barcode')
            ->where('barcode', 'like', $like)
            ->pluck('barcode');

        $barcode = $productBarcodes
            ->merge($productUnitBarcodes)
            ->sortByDesc(fn ($barcode) => (int) substr(strrchr($barcode, '-'), 1))
            ->first();

        return response()->json(['barcode' => $barcode], 200);
    }

    public function saleproducts(Request $request){
        $warehouseId = $request->warehouse_id;
        $branchId = $request->branch_id
            ? (int) $request->branch_id
            : ($warehouseId ? Branch::where('warehouse_id', $warehouseId)->value('id') : null);
        $priceResolver = app(SellingPriceService::class);

        $products = Product::query()
            ->leftJoin('inventories', function ($join) use ($warehouseId) {
                $join->on('products.id', '=', 'inventories.product_id')
                    ->where('inventories.qty', '>', 0);

                if ($warehouseId) {
                    $join->where('inventories.warehouse_id', $warehouseId);
                }
            })
            ->select(
                'products.id',
                'products.image',
                'products.name',
                'products.default_product_unit_id',
                'products.uom_enabled',
                'products.price',
                'products.barcode',
                DB::raw('COALESCE(SUM(inventories.qty), 0) as qty')
            )
            ->with([
                'productUnits.unit',
                'productUnits.priceRanges',
                'productUnits.branchUnitPrices.branchProduct',
                'productUnits.branchUnitPrices.priceRanges',
            ])
            ->groupBy(
                'products.id',
                'products.image',
                'products.name',
                'products.default_product_unit_id',
                'products.uom_enabled',
                'products.price',
                'products.barcode'
            )
            ->get();

            return response()->json(
                $products->map(function ($p) use ($branchId, $priceResolver) {
                    $productPrice = $priceResolver->resolve((int) $p->id, $branchId);

                    return [
                        'id'        => $p->id,
                        'name'      => $p->name,
                        'price'     => $productPrice['price'],
                        'price_source' => $productPrice['source'],
                        'branch_product_id' => $productPrice['branch_product_id'],
                        'qty'       => (int) $p->qty,
                        'image_url' => $p->image ? asset($p->image) : asset('assets/img/products/default.png'),
                        'barcode' => $p->barcode,
                        'default_product_unit_id' => $p->default_product_unit_id,
                        'uom_enabled' => $p->uom_enabled,
                        'product_units' => $p->productUnits->map(function ($productUnit) use ($branchId, $priceResolver) {
                            $unitPrice = $priceResolver->resolve(
                                (int) $productUnit->product_id,
                                $branchId,
                                (int) $productUnit->id,
                                1
                            );
                            $branchUnitPrice = $branchId
                                ? $productUnit->branchUnitPrices->first(
                                    fn ($price) => (int) ($price->branchProduct->branch_id ?? 0) === (int) $branchId
                                )
                                : null;
                            $rangeSource = $branchUnitPrice
                                ? $branchUnitPrice->priceRanges
                                : $productUnit->priceRanges;

                            return [
                                'id' => $productUnit->id,
                                'unit_id' => $productUnit->unit_id,
                                'unit_name' => $productUnit->unit->name ?? null,
                                'barcode' => $productUnit->barcode,
                                'conversion_to_base' => $productUnit->conversion_to_base,
                                'price' => $unitPrice['price'],
                                'price_source' => $unitPrice['source'],
                                'branch_product_unit_price_id' => $unitPrice['branch_product_unit_price_id'],
                                'purchase_price' => $productUnit->purchase_price,
                                'is_base_unit' => $productUnit->is_base_unit,
                                'is_default_sale_unit' => $productUnit->is_default_sale_unit,
                                'price_ranges' => $rangeSource->map(function ($range) use ($branchUnitPrice) {
                                    return [
                                        'id' => $range->id,
                                        'min_qty' => $range->min_qty,
                                        'max_qty' => $range->max_qty,
                                        'price' => $range->price,
                                        'price_source' => $branchUnitPrice
                                            ? 'BRANCH_UOM_PRICE_RANGE'
                                            : 'GLOBAL_UOM_PRICE_RANGE',
                                        'branch_product_unit_price_range_id' => $branchUnitPrice ? $range->id : null,
                                        'product_unit_price_range_id' => $branchUnitPrice ? null : $range->id,
                                    ];
                                }),
                            ];
                        }),
                    ];
                })
            );
    }

    private function productDeleteBlockers(int $productId): array
    {
        $tables = [
            'inventories' => 'inventory records',
            'sale_details' => 'sale records',
            'sale_return_details' => 'sale return records',
            'purchase_details' => 'purchase records',
            'purchase_return_details' => 'purchase return records',
            'promotion_foc_allocations' => 'FOC promotion allocations',
        ];

        $blockers = [];

        foreach ($tables as $table => $label) {
            if (DB::table($table)->where('product_id', $productId)->exists()) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }

    private function productRelations(): array
    {
        return [
            'unit',
            'category',
            'status',
            'createdBy',
            'updatedBy',
            'defaultProductUnit.unit',
            'defaultProductUnit.priceRanges',
            'productUnits.unit',
            'productUnits.status',
            'productUnits.createdBy',
            'productUnits.updatedBy',
            'productUnits.priceRanges.status',
            'productUnits.priceRanges.createdBy',
            'productUnits.priceRanges.updatedBy',
            'branchProducts.branch',
            'branchProducts.status',
            'branchProducts.createdBy',
            'branchProducts.updatedBy',
            'branchProducts.unitPrices.productUnit.unit',
            'branchProducts.unitPrices.unit',
            'branchProducts.unitPrices.status',
            'branchProducts.unitPrices.createdBy',
            'branchProducts.unitPrices.updatedBy',
            'branchProducts.unitPrices.priceRanges.status',
            'branchProducts.unitPrices.priceRanges.createdBy',
            'branchProducts.unitPrices.priceRanges.updatedBy',
        ];
    }

    private function productRules(Request $request, ?Product $product = null): array
    {
        $productId = $product?->id;
        $isUpdate = (bool) $product;
        $productUnitRules = $this->productUnitRules($request);
        $branchProductRules = $this->branchProductRules($request);

        return array_merge([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'sec_prop' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'purchase_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'old_purchase_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'old_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif', 'max:2048'],
            'status_id' => [$isUpdate ? 'sometimes' : 'required', 'required', 'exists:statuses,id'],
            'created_by' => [$isUpdate ? 'sometimes' : 'required', 'required', 'exists:users,id'],
            'updated_by' => ['nullable', 'exists:users,id'],
            'uom_enabled' => ['nullable', 'boolean'],
            'default_product_unit_id' => ['nullable', 'exists:product_units,id'],
            'product_units' => ['nullable', 'array'],
            'branch_products' => ['nullable', 'array'],
        ], $productUnitRules, $branchProductRules);
    }

    private function productUnitRules(Request $request): array
    {
        $rules = [
            'product_units.*.id' => ['nullable', 'exists:product_units,id'],
            'product_units.*.unit_id' => ['required_with:product_units', 'exists:units,id'],
            'product_units.*.barcode' => ['nullable', 'string', 'max:255', 'distinct'],
            'product_units.*.conversion_to_base' => ['required_with:product_units', 'numeric', 'gt:0'],
            'product_units.*.price' => ['required_with:product_units', 'numeric', 'min:0'],
            'product_units.*.old_price' => ['nullable', 'numeric', 'min:0'],
            'product_units.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'product_units.*.old_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'product_units.*.is_base_unit' => ['nullable', 'boolean'],
            'product_units.*.is_default_sale_unit' => ['nullable', 'boolean'],
            'product_units.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'product_units.*.status_id' => ['nullable', 'exists:statuses,id'],
            'product_units.*.price_ranges' => ['nullable', 'array'],
            'product_units.*.price_ranges.*.id' => ['nullable', 'exists:product_unit_price_ranges,id'],
            'product_units.*.price_ranges.*.min_qty' => ['required', 'numeric', 'min:0'],
            'product_units.*.price_ranges.*.max_qty' => ['nullable', 'numeric', 'gt:0'],
            'product_units.*.price_ranges.*.price' => ['required', 'numeric', 'min:0'],
            'product_units.*.price_ranges.*.old_price' => ['nullable', 'numeric', 'min:0'],
            'product_units.*.price_ranges.*.status_id' => ['nullable', 'exists:statuses,id'],
        ];

        foreach ($request->input('product_units', []) as $index => $productUnit) {
            $rules["product_units.{$index}.barcode"][] = Rule::unique('product_units', 'barcode')
                ->ignore($productUnit['id'] ?? null);
        }

        return $rules;
    }

    private function branchProductRules(Request $request): array
    {
        return [
            'branch_products.*.id' => ['nullable', 'exists:branch_products,id'],
            'branch_products.*.branch_id' => ['required_with:branch_products', 'exists:branches,id', 'distinct'],
            'branch_products.*.price' => ['nullable', 'numeric', 'min:0'],
            'branch_products.*.old_price' => ['nullable', 'numeric', 'min:0'],
            'branch_products.*.status_id' => ['nullable', 'exists:statuses,id'],
            'branch_products.*.unit_prices' => ['nullable', 'array'],
            'branch_products.*.unit_prices.*.id' => ['nullable', 'exists:branch_product_unit_prices,id'],
            'branch_products.*.unit_prices.*.product_unit_id' => ['nullable', 'exists:product_units,id'],
            'branch_products.*.unit_prices.*.unit_id' => ['nullable', 'exists:units,id'],
            'branch_products.*.unit_prices.*.price' => ['required_with:branch_products.*.unit_prices', 'numeric', 'min:0'],
            'branch_products.*.unit_prices.*.old_price' => ['nullable', 'numeric', 'min:0'],
            'branch_products.*.unit_prices.*.status_id' => ['nullable', 'exists:statuses,id'],
            'branch_products.*.unit_prices.*.price_ranges' => ['nullable', 'array'],
            'branch_products.*.unit_prices.*.price_ranges.*.id' => ['nullable', 'exists:branch_product_unit_price_ranges,id'],
            'branch_products.*.unit_prices.*.price_ranges.*.min_qty' => ['required', 'numeric', 'min:0'],
            'branch_products.*.unit_prices.*.price_ranges.*.max_qty' => ['nullable', 'numeric', 'gt:0'],
            'branch_products.*.unit_prices.*.price_ranges.*.price' => ['required', 'numeric', 'min:0'],
            'branch_products.*.unit_prices.*.price_ranges.*.old_price' => ['nullable', 'numeric', 'min:0'],
            'branch_products.*.unit_prices.*.price_ranges.*.status_id' => ['nullable', 'exists:statuses,id'],
        ];
    }

    private function syncProductUnits(Product $product, array $productUnits, int $createdBy, int $updatedBy): void
    {
        foreach ($productUnits as $productUnitData) {
            $productUnit = !empty($productUnitData['id'])
                ? $product->productUnits()->whereKey($productUnitData['id'])->firstOrFail()
                : new ProductUnit(['product_id' => $product->id]);

            $productUnit->fill([
                'unit_id' => $productUnitData['unit_id'],
                'barcode' => $productUnitData['barcode'] ?? null,
                'conversion_to_base' => $productUnitData['conversion_to_base'],
                'price' => $productUnitData['price'],
                'old_price' => $productUnitData['old_price'] ?? $productUnitData['price'],
                'purchase_price' => $productUnitData['purchase_price'] ?? 0,
                'old_purchase_price' => $productUnitData['old_purchase_price'] ?? $productUnitData['purchase_price'] ?? 0,
                'is_base_unit' => $productUnitData['is_base_unit'] ?? false,
                'is_default_sale_unit' => $productUnitData['is_default_sale_unit'] ?? false,
                'sort_order' => $productUnitData['sort_order'] ?? 0,
                'status_id' => $productUnitData['status_id'] ?? $product->status_id,
                'created_by' => $productUnit->exists ? $productUnit->created_by : $createdBy,
                'updated_by' => $updatedBy,
            ]);

            $productUnit->save();

            $this->syncProductUnitPriceRanges(
                $productUnit,
                $productUnitData['price_ranges'] ?? [],
                $createdBy,
                $updatedBy
            );
        }
    }

    private function syncProductUnitPriceRanges(ProductUnit $productUnit, array $priceRanges, int $createdBy, int $updatedBy): void
    {
        foreach ($priceRanges as $rangeData) {
            $priceRange = !empty($rangeData['id'])
                ? $productUnit->priceRanges()->whereKey($rangeData['id'])->firstOrFail()
                : $productUnit->priceRanges()->make();

            $priceRange->fill([
                'min_qty' => $rangeData['min_qty'],
                'max_qty' => $rangeData['max_qty'] ?? null,
                'price' => $rangeData['price'],
                'old_price' => $rangeData['old_price'] ?? $rangeData['price'],
                'status_id' => $rangeData['status_id'] ?? $productUnit->status_id,
                'created_by' => $priceRange->exists ? $priceRange->created_by : $createdBy,
                'updated_by' => $updatedBy,
            ]);

            $priceRange->save();
        }
    }

    private function syncBranchProducts(Product $product, array $branchProducts, int $createdBy, int $updatedBy): void
    {
        if (empty($branchProducts)) {
            return;
        }

        $product->loadMissing('productUnits.unit');

        foreach ($branchProducts as $branchProductData) {
            $branchProduct = !empty($branchProductData['id'])
                ? $product->branchProducts()->whereKey($branchProductData['id'])->firstOrFail()
                : $product->branchProducts()->firstOrNew([
                    'branch_id' => $branchProductData['branch_id'],
                ]);

            $branchProduct->fill([
                'branch_id' => $branchProductData['branch_id'],
                'price' => $branchProductData['price'] ?? null,
                'old_price' => $branchProductData['old_price'] ?? $branchProductData['price'] ?? null,
                'status_id' => $branchProductData['status_id'] ?? $product->status_id,
                'created_by' => $branchProduct->exists ? $branchProduct->created_by : $createdBy,
                'updated_by' => $updatedBy,
            ]);

            $branchProduct->save();

            $this->syncBranchProductUnitPrices(
                $product,
                $branchProduct,
                $branchProductData['unit_prices'] ?? [],
                $createdBy,
                $updatedBy
            );
        }
    }

    private function syncBranchProductUnitPrices(
        Product $product,
        BranchProduct $branchProduct,
        array $unitPrices,
        int $createdBy,
        int $updatedBy
    ): void {
        foreach ($unitPrices as $unitPriceData) {
            $productUnit = $this->resolveBranchPriceProductUnit($product, $unitPriceData);

            $branchUnitPrice = !empty($unitPriceData['id'])
                ? $branchProduct->unitPrices()->whereKey($unitPriceData['id'])->firstOrFail()
                : $branchProduct->unitPrices()->firstOrNew([
                    'product_unit_id' => $productUnit->id,
                ]);

            $branchUnitPrice->fill([
                'product_unit_id' => $productUnit->id,
                'unit_id' => $productUnit->unit_id,
                'unit_name' => $productUnit->unit->name ?? $unitPriceData['unit_name'] ?? null,
                'conversion_to_base' => $productUnit->conversion_to_base,
                'price' => $unitPriceData['price'],
                'old_price' => $unitPriceData['old_price'] ?? $unitPriceData['price'],
                'status_id' => $unitPriceData['status_id'] ?? $branchProduct->status_id,
                'created_by' => $branchUnitPrice->exists ? $branchUnitPrice->created_by : $createdBy,
                'updated_by' => $updatedBy,
            ]);

            $branchUnitPrice->save();

            $this->syncBranchProductUnitPriceRanges(
                $branchUnitPrice,
                $unitPriceData['price_ranges'] ?? [],
                $createdBy,
                $updatedBy
            );
        }
    }

    private function syncBranchProductUnitPriceRanges(
        BranchProductUnitPrice $branchUnitPrice,
        array $priceRanges,
        int $createdBy,
        int $updatedBy
    ): void {
        foreach ($priceRanges as $rangeData) {
            $priceRange = !empty($rangeData['id'])
                ? $branchUnitPrice->priceRanges()->whereKey($rangeData['id'])->firstOrFail()
                : $branchUnitPrice->priceRanges()->make();

            $priceRange->fill([
                'min_qty' => $rangeData['min_qty'],
                'max_qty' => $rangeData['max_qty'] ?? null,
                'price' => $rangeData['price'],
                'old_price' => $rangeData['old_price'] ?? $rangeData['price'],
                'status_id' => $rangeData['status_id'] ?? $branchUnitPrice->status_id,
                'created_by' => $priceRange->exists ? $priceRange->created_by : $createdBy,
                'updated_by' => $updatedBy,
            ]);

            $priceRange->save();
        }
    }

    private function resolveBranchPriceProductUnit(Product $product, array $unitPriceData): ProductUnit
    {
        if (!empty($unitPriceData['product_unit_id'])) {
            $productUnit = $product->productUnits
                ->firstWhere('id', (int) $unitPriceData['product_unit_id']);

            if ($productUnit) {
                return $productUnit;
            }

            throw ValidationException::withMessages([
                'branch_products' => "Product unit {$unitPriceData['product_unit_id']} does not belong to product {$product->id}.",
            ]);
        }

        if (!empty($unitPriceData['unit_id'])) {
            $productUnit = $product->productUnits
                ->firstWhere('unit_id', (int) $unitPriceData['unit_id']);

            if ($productUnit) {
                return $productUnit;
            }

            throw ValidationException::withMessages([
                'branch_products' => "No product unit with unit_id {$unitPriceData['unit_id']} exists for product {$product->id}.",
            ]);
        }

        throw ValidationException::withMessages([
            'branch_products' => 'Each branch unit price requires product_unit_id or unit_id.',
        ]);
    }

    private function syncDefaultProductUnit(Product $product, mixed $requestedDefaultProductUnitId = null): void
    {
        $defaultProductUnit = null;

        if ($requestedDefaultProductUnitId) {
            $defaultProductUnit = $product->productUnits()
                ->whereKey($requestedDefaultProductUnitId)
                ->first();
        }

        $defaultProductUnit ??= $product->productUnits()
            ->where('is_default_sale_unit', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $defaultProductUnit ??= $product->productUnits()
            ->where('is_base_unit', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (!$defaultProductUnit) {
            return;
        }

        $product->forceFill([
            'default_product_unit_id' => $defaultProductUnit->id,
            'unit_id' => $product->unit_id ?? $defaultProductUnit->unit_id,
            'price' => $product->price ?: $defaultProductUnit->price,
            'purchase_price' => $product->purchase_price ?: $defaultProductUnit->purchase_price,
            'barcode' => $product->barcode ?? $defaultProductUnit->barcode,
            'uom_enabled' => true,
        ])->save();
    }

    private function normalizeProductUnitsInput(Request $request): void
    {
        if (!$request->has('product_units') || !is_string($request->product_units)) {
            return;
        }

        $decoded = json_decode($request->product_units, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $request->merge(['product_units' => $decoded]);
        }
    }

    private function normalizeBranchProductsInput(Request $request): void
    {
        if (!$request->has('branch_products') || !is_string($request->branch_products)) {
            return;
        }

        $decoded = json_decode($request->branch_products, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $request->merge(['branch_products' => $decoded]);
        }
    }
}

// ->having('qty', '>', 0) // only sellable products

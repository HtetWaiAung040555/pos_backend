<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PromotionsController extends Controller
{
    public function index()
    {
        $now = now();

        $inactiveStatus = Status::where('name', 'inactive')->value('id');
        $activeStatus   = Status::where('name', 'active')->value('id');

        DB::transaction(function () use ($now, $inactiveStatus, $activeStatus) {

            Promotion::whereNull('void_at')
                ->where(function ($q) use ($now) {
                    $q->where('start_at', '>', $now)
                    ->orWhere('end_at', '<', $now);
                })
                ->update(['status_id' => $inactiveStatus]);

            Promotion::whereNull('void_at')
                ->where('start_at', '<=', $now)
                ->where('end_at', '>=', $now)
                ->update(['status_id' => $activeStatus]);
        });

        $promotions = Promotion::with('products')->get();
        return PromotionResource::collection($promotions);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'discount_type'   => 'required|in:PERCENT,AMOUNT',
            'discount_value'  => 'required|numeric',
            'start_at'        => 'required|date',
            'end_at'          => 'required|date',
            'products'        => 'nullable|array',
            'products.*'      => 'integer|exists:products,id',
            'created_by'      => 'required|exists:users,id',
            'updated_by'      => 'nullable|exists:users,id'
        ]);

        // Check if any product is already inside an active promotion
        // $existing = $this->checkProductAlreadyInPromotion($request->products ?? []);
        // if ($existing) {
        //     return response()->json([
        //         'error'        => 'Some products are already in another active promotion.',
        //         'promotion_id' => $existing->id,
        //     ], 422);
        // }

        $promotion = Promotion::create([
            'name'           => $request->name,
            'description'    => $request->description,
            'discount_type'  => $request->discount_type,
            'discount_value' => $request->discount_value,
            'start_at'       => $request->start_at,
            'end_at'         => $request->end_at,
            'status_id'      => $request->status_id,
            'created_by'     => $request->created_by,
            'updated_by'     => $request->updated_by ?? $request->created_by
        ]);

        if ($request->products) {
            $promotion->products()->sync($request->products);
        }

        return new PromotionResource($promotion->fresh(['products']));
    }

    public function show(string $id)
    {
        $promotion = Promotion::with('products')->findOrFail($id);
        return new PromotionResource($promotion);
    }

    public function update(Request $request, string $id)
    {
        $promotion = Promotion::findOrFail($id);

        $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'description'     => 'nullable|string',
            'discount_type'   => 'sometimes|required|in:PERCENT,AMOUNT',
            'discount_value'  => 'sometimes|required|numeric',
            'start_at'        => 'sometimes|required|date',
            'end_at'          => 'sometimes|required|date',
            'products'        => 'nullable|array',
            'products.*'      => 'integer|exists:products,id',
            'created_by'      => 'sometimes|required|exists:users,id',
            'updated_by'      => 'nullable|exists:users,id'
        ]);

        // If products are being changed, check conflicts
        // if ($request->has('products')) {
        //     $existing = $this->checkProductAlreadyInPromotion(
        //         $request->products ?? [],
        //         $promotion->id
        //     );

        //     if ($existing) {
        //         return response()->json([
        //             'error'        => 'Some products are already in another active promotion.',
        //             'promotion_id' => $existing->id,
        //         ], 422);
        //     }
        // }

        $promotion->update([
            'name'           => $request->name ?? $promotion->name,
            'description'    => $request->description ?? $promotion->description,
            'discount_type'  => $request->discount_type ?? $promotion->discount_type,
            'discount_value' => $request->discount_value ?? $promotion->discount_value,
            'status_id'      => $request->status_id ?? $promotion->status_id,
            'start_at'       => $request->start_at ?? $promotion->start_at,
            'end_at'         => $request->end_at ?? $promotion->end_at,
            'updated_by'     => $request->updated_by ?? $promotion->updated_by
        ]);

        if ($request->has('products')) {
            $promotion->products()->sync($request->products ?? []);
        }

        return new PromotionResource($promotion->fresh(['products']));
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            $promotion = Promotion::with('products')->findOrFail($id);

            $voidStatus = Status::where('name', 'void')->firstOrFail();

            $promotion->status_id = $voidStatus->id;
            $promotion->void_at   = now();
            $promotion->void_by   = $request->void_by;
            $promotion->save();

            $promotion->products()->sync([]);

            DB::commit();

            return response()->json([
                'message' => 'Promotion voided successfully.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error'   => 'Failed to void promotion',
                'details' => $e->getMessage()
            ], 500);
        }
    }



    public function checkPrice(Request $request)
    {
        $product = Product::find($request->product_id);

        if (!$product) {
            return response()->json([
                'promotion_id'    => null,
                'discount_amount' => 0
            ]);
        }

        $id  = $request->product_id;

        $now = now();

        $checkDate = $request->sale_date ? $request->sale_date : $now;

        $promotion = Promotion::whereHas('products', function ($q) use ($id) {
                $q->where('product_id', $id);
            })
            ->where('status_id', 1)
            ->where('start_at', '<=', $checkDate)
            ->where('end_at', '>=', $checkDate)
            ->first();

        $discount_amount = 0;

        if ($promotion) {
            $discount_amount = $promotion->discount_type === 'PERCENT'
                ? ($product->price * $promotion->discount_value) / 100
                : $promotion->discount_value;
        }

        return response()->json([
            'promotion_id'    => $promotion ? $promotion->id : null,
            'discount_type'   => $promotion ? $promotion->discount_type : null,
            'discount_value'  => $promotion ? $promotion->discount_value : 0,
            'discount_amount' => $discount_amount
        ]);
    }

    public function syncFromCloud(Request $request)
    {
        try {
            $response = Http::withToken(config('services.cloud.token'))
                ->timeout(20)
                ->retry(3, 200)
                ->get(config('services.cloud.url') . '/api/promotions');

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            $promotions = $response->json('data');

            if (!is_array($promotions)) {
                return response()->json([
                    'message' => 'Invalid promotion data'
                ], 500);
            }

            DB::beginTransaction();

            foreach ($promotions as $item) {

                $promotion = Promotion::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name'           => $item['name'],
                        'description'    => $item['description'],
                        'discount_type'  => $item['discount_type'],
                        'discount_value' => (float) ($item['discount_value']),
                        'start_at'       => $item['start_at'],
                        'end_at'         => $item['end_at'],
                        'status_id'      => $item['status']['id'],
                        'void_at'        => $item['void_at'],
                        'void_by'        => $item['void_by']['id'] ?? null,
                        'created_by'     => $item['created_by']['id'],
                        'created_at' => $item['created_at'],
                        'updated_by'     => $request->updated_by
                    ]
                );

                if (!empty($item['products']) && is_array($item['products'])) {
                    $productIds = collect($item['products'])->pluck('id')->filter()->toArray();
                    $promotion->products()->sync($productIds);
                } else {
                    $promotion->products()->sync([]);
                }
            }

            DB::commit();

            return response()->json(['message' => 'success'], 200);

        } catch (\Throwable $e) {


            DB::rollBack();

            return response()->json([
                'message' => 'An error occurred during promotion sync',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function syncToCloud(Request $request)
    {

        $cloudApiUrl = config('services.cloud.url') . '/api/promotions';
        $apiToken    = config('services.cloud.token');

        $synced = [];
        $failed = [];

        Promotion::with('products')
            ->where('is_synced', false)
            ->orderBy('id')
            ->chunkById(30, function ($promotions) use ($cloudApiUrl, $apiToken, $request, &$synced, &$failed) {

                foreach ($promotions as $promotion) {

                    try {

                        DB::beginTransaction();

                        $promotion = Promotion::lockForUpdate()->find($promotion->id);

                        if ($promotion->is_synced) {
                            DB::commit();
                            continue;
                        }

                        Log::info('Processing promotion for sync', ['promotion_id' => $promotion->id, 'is_synced' => $promotion->is_synced]);

                        $payload = [
                            'id' => $promotion->id,
                            'name' => $promotion->name,
                            'description' => $promotion->description,
                            'discount_type' => $promotion->discount_type,
                            'discount_value' => (float) $promotion->discount_value,
                            'start_at' => $promotion->start_at,
                            'end_at' => $promotion->end_at,
                            'status_id' => $promotion->status_id,
                            'created_by' => $promotion->created_by,
                            'updated_by' => $promotion->updated_by,
                            'products' => $promotion->products->map(function ($product) {
                                return $product->id;
                            })->toArray()
                        ];

                        $response = Http::withToken($apiToken)
                            ->post($cloudApiUrl, $payload);
                        
                        $data = $response->json('data') ?? null;

                        if ($data) {
                            
                            $promotion->update([
                                'is_synced' => true,
                                'synced_at' => now(),
                                'updated_by' => $request->updated_by
                            ]);

                            DB::commit();

                            $synced[] = $promotion->id;

                        } else {

                            DB::rollBack();

                            $failed[] = [
                                'id' => $promotion->id,
                                'error' => $response->body()
                            ];

                        }
                    } catch (\Throwable $e) { 

                        DB::rollBack();

                        $failed[] = [
                            'id' => $promotion->id,
                            'error' => $e->getMessage()
                        ];

                    }

                }
            });
        
        return response()->json([
            'message' => 'Sync process completed',
            'synced' => count($synced),
            'failed' => count($failed),
            'failed_details' => $failed
        ]);

    }

    // public function syncFromCloud(Request $request)
    // {
    //     try {

    //         $response = Http::withToken(config('services.cloud.token'))
    //             ->timeout(20)
    //             ->retry(3, 200)
    //             ->get(config('services.cloud.url') . '/api/promotions');

    //         if (!$response->successful()) {
    //             return response()->json([
    //                 'message' => 'Cloud API request failed',
    //                 'status'  => $response->status()
    //             ], 500);
    //         }

    //         $promotions = $response->json('data');

    //         if (!is_array($promotions)) {
    //             return response()->json([
    //                 'message' => 'Invalid promotion data'
    //             ], 500);
    //         }

    //         DB::beginTransaction();

    //         $promotionUpserts = [];
    //         $pivotRows = [];

    //         foreach ($promotions as $item) {

    //             if (!isset($item['id'])) {
    //                 continue;
    //             }

    //             $promotionUpserts[] = [
    //                 'id'             => $item['id'],
    //                 'name'           => $item['name'] ?? null,
    //                 'description'    => $item['description'] ?? null,
    //                 'discount_type'  => $item['discount_type'] ?? null,
    //                 'discount_value' => (float) ($item['discount_value'] ?? 0),
    //                 'start_at'       => $item['start_at'] ?? null,
    //                 'end_at'         => $item['end_at'] ?? null,
    //                 'status_id'      => $item['status']['id'] ?? null,
    //                 'void_at'        => $item['void_at'] ?? null,
    //                 'void_by'        => $item['void_by']['id'] ?? null,
    //                 'created_by'     => $item['created_by']['id'] ?? null,
    //                 'created_at'     => $item['created_at'] ?? now(),
    //                 'updated_by'     => $request->updated_by,
    //                 'updated_at'     => now(),
    //             ];

    //             if (!empty($item['products']) && is_array($item['products'])) {

    //                 foreach ($item['products'] as $product) {

    //                     if (!isset($product['id'])) {
    //                         continue;
    //                     }

    //                     $pivotRows[] = [
    //                         'promotion_id' => $item['id'],
    //                         'product_id'   => $product['id']
    //                     ];
    //                 }
    //             }
    //         }

    //         if (!empty($promotionUpserts)) {

    //             Promotion::upsert(
    //                 $promotionUpserts,
    //                 ['id'],
    //                 [
    //                     'name',
    //                     'description',
    //                     'discount_type',
    //                     'discount_value',
    //                     'start_at',
    //                     'end_at',
    //                     'status_id',
    //                     'void_at',
    //                     'void_by',
    //                     'updated_by',
    //                     'updated_at'
    //                 ]
    //             );
    //         }

    //         /* Sync pivot table */

    //         if (!empty($pivotRows)) {

    //             DB::table('product_promotion')->truncate();

    //             DB::table('product_promotion')->insert($pivotRows);
    //         }

    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Promotions synced successfully',
    //             'count'   => count($promotionUpserts)
    //         ]);

    //     } catch (\Throwable $e) {

    //         DB::rollBack();

    //         return response()->json([
    //             'message' => 'Promotion sync failed',
    //             'error'   => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // Check if products are already inside another active promotion.
    // private function checkProductAlreadyInPromotion($products, $ignorePromotionId = null)
    // {
    //     if (empty($products)) {
    //         return null;
    //     }

    //     $now = now();

    //     return Promotion::where('id', '!=', $ignorePromotionId)
    //         ->whereHas('products', function ($q) use ($products) {
    //             $q->whereIn('products.id', $products);
    //         })
    //         ->where('start_at', '<=', $now)
    //         ->where('end_at', '>=', $now)
    //         ->first();
    // }
}

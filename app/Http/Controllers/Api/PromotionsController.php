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
        // $activeStatus   = Status::where('name', 'active')->value('id');

        DB::transaction(function () use ($now, $inactiveStatus) {

            Promotion::whereNull('void_at')
                ->where(function ($q) use ($now) {
                    $q->where('start_at', '>', $now)
                    ->orWhere('end_at', '<', $now);
                })
                ->update(['status_id' => $inactiveStatus]);

            // Promotion::whereNull('void_at')
            //     ->where('start_at', '<=', $now)
            //     ->where('end_at', '>=', $now)
            //     ->update(['status_id' => $activeStatus]);
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

            $voidStatus = \App\Models\Status::where('name', 'void')->firstOrFail();

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



    public function checkPrice($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'promotion_id'    => null,
                'discount_amount' => 0
            ]);
        }

        $now = now();

        $promotion = Promotion::whereHas('products', function ($q) use ($id) {
                $q->where('product_id', $id);
            })
            ->where('status_id', 1)
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
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

    public function syncFromCloud(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
                ->get(env('CLOUD_API_URL') . '/api/promotions');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            $promotions = $response->json('data');

            if (! is_array($promotions)) {
                return response()->json([
                    'message' => 'Invalid promotion data format'
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
                        'updated_by'     => $request->updated_by
                    ]
                );

                if (!empty($item['products']) && is_array($item['products'])) {
                    $productIds = collect($item['products'])
                        ->pluck('id')
                        ->filter()
                        ->toArray();

                    $promotion->products()->sync($productIds);
                } else {
                    $promotion->products()->sync([]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Promotions synced successfully'
            ], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            // Log::error('Promotion sync failed', [
            //     'error' => $e->getMessage(),
            //     'line'  => $e->getLine()
            // ]);

            return response()->json([
                'message' => 'An error occurred during promotion sync'
            ], 500);
        }
    }


}

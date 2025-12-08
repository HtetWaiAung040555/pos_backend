<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use App\Http\Resources\PromotionResource;
use App\Models\Product;
use App\Models\Promotion;

class PromotionsController extends Controller
{
    public function index()
    {
        $promotions = Promotion::with('products')->get();
        return PromotionResource::collection($promotions);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'discount_type'   => 'required|in:PERCENT,AMOUNT',
            'discount_value'  => 'required|numeric',
            'start_at'        => 'required|date',
            'end_at'          => 'required|date',
            'products'        => 'nullable|array',
            'products.*'      => 'integer|exists:products,id',
        ]);

        $promotion = Promotion::create($data);

        if (!empty($data['products'])) {
            $promotion->products()->sync($data['products']);
        }

        return new PromotionResource($promotion->load('products'));
    }

    public function show(string $id)
    {
        $promotion = Promotion::with('products')->findOrFail($id);
        return new PromotionResource($promotion);
    }

    public function update(Request $request, string $id)
    {
        $promotion = Promotion::findOrFail($id);

        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'description'     => 'nullable|string',
            'discount_type'   => 'sometimes|required|in:PERCENT,AMOUNT',
            'discount_value'  => 'sometimes|required|numeric',
            'start_at'        => 'sometimes|required|date',
            'end_at'          => 'sometimes|required|date',
            'products'        => 'nullable|array',
            'products.*'      => 'integer|exists:products,id',
        ]);

        $promotion->update($data);

        if ($request->has('products')) {
            $promotion->products()->sync($request->products ?? []);
        }

        return new PromotionResource($promotion->load('products'));
    }

    public function destroy(string $id)
    {
        try {
            Promotion::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Promotion cannot be deleted'], 400);
        }
    }

    // public function checkPrice($id)
    // {
    //     $product = Product::findOrFail($id);

    //     if (!$product) {
    //         return response()->json([
    //             'promotion_id' => null,
    //             'discount_amount' => 0
    //         ]);
    //     }
    
    //     $now = now();

    //     $promotion = Promotion::whereHas('products', function ($q) use ($id) {
    //             $q->where('product_id', $id);
    //         })
    //         ->where('start_at', '<=', $now)
    //         ->where('end_at', '>=', $now)
    //         ->first();

    //     $discount_amount = 0;

    //     if ($promotion) {
    //         if ($promotion->discount_type === 'PERCENT') {
    //             $discount_amount = ($product->price * $promotion->discount_value) / 100;
    //         } else {
    //             $discount_amount = $promotion->discount_value;
    //         }
    //     }

    //     return response()->json([
    //         'promotion_id' => $promotion ? $promotion->id : null,
    //         'discount_amount' => $discount_amount
    //     ]);
    // }

    public function checkPrice($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'promotion_id' => null,
                'discount_amount' => 0
            ]);
        }

        $now = now();

        $promotion = Promotion::whereHas('products', function ($q) use ($id) {
                $q->where('product_id', $id);
            })
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
            'promotion_id' => $promotion ? $promotion->id : null,
            'discount_amount' => $discount_amount
        ]);
    }



}

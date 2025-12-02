<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;

class PromotionsController extends Controller
{
    public function index() {
        return Promotion::with('products')->get();
    }

    public function store(Request $request) {
        $data = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'discount_type' => 'required|in:PERCENT,AMOUNT',
            'discount_value' => 'required|numeric',
            'start_at' => 'required|date',
            'end_at' => 'required|date',
            'products' => 'array'
        ]);

        $promotion = Promotion::create($data);

        if (!empty($data['products'])) {
            $promotion->products()->sync($data['products']);
        }
        $promotion->load('products');

        return response()->json($promotion, 201);
    }

    public function show($id) {
        return Promotion::with('products')->findOrFail($id);
    }

    public function update(Request $request, $id) {
        $promotion = Promotion::findOrFail($id);
        $promotion->update($request->all());

        if ($request->has('products')) {
            $promotion->products()->sync($request->products);
        }
        $promotion->load('products');

        return response()->json($promotion);
    }

    public function destroy($id) {
        Promotion::destroy($id);
        return response()->json(['message' => 'Promotion deleted']);
    }
}

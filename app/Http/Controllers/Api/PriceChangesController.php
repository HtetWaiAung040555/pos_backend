<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PriceChangeResource;
use App\Models\PriceChange;
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
        
    }

    public function show(string $id)
    {
        $price_changes = PriceChange::with('products')->findOrFail($id);
        return new PriceChangeResource($price_changes);
    }

    public function update(Request $request, string $id)
    {
        
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            $price_change = PriceChange::with('products')->findOrFail($id);

            $voidStatus = \App\Models\Status::where('name', 'void')->firstOrFail();

            $price_change->status_id = $voidStatus->id;
            $price_change->void_at   = now();
            $price_change->void_by   = $request->void_by;
            $price_change->save();

            $price_change->products()->sync([]);

            DB::commit();

            return response()->json([
                'message' => 'Price Chage voided successfully.'
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

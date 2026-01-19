<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockTransactionResource;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StockTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransaction::query()->with(["inventory.product"]);

        // Filter by inventory
        if ($request->filled("inventory_id")) {
            $query->where("inventory_id", $request->inventory_id);
        }

        // Filter by reference type
        if ($request->filled("reference_type")) {
            $query->where("reference_type", $request->reference_type);
        }

        // Filter by in / out
        if ($request->filled("type")) {
            $query->where("type", $request->type);
        }

        // Search by reference_id
        if ($request->filled("search")) {
            $query->where("reference_id", "like", "%" . $request->search . "%");
        }

        // Date range filter
        if ($request->filled("start_date") && $request->filled("end_date")) {
            $query->whereBetween("reference_date", [
                $request->start_date,
                $request->end_date,
            ]);
        } elseif ($request->filled("start_date")) {
            $query->whereDate("reference_date", ">=", $request->start_date);
        } elseif ($request->filled("end_date")) {
            $query->whereDate("reference_date", "<=", $request->end_date);
        }

        // ⬇Latest first
        $transactions = $query->orderBy("created_at", "desc")->get();

        return StockTransactionResource::collection($transactions);
    }

    public function store(Request $request)
    {
        //
    }

    public function show(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {
        //
    }

    public function destroy(string $id)
    {
        //
    }
}

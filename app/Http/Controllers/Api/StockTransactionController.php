<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransaction;
use Illuminate\Http\Request;

class StockTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransaction::query()
            ->with([
                'inventory.product', // optional, if relation exists
                'createdBy:id,name'     // created_by user
            ]);

        // 🔍 Filter by inventory
        if ($request->filled('inventory_id')) {
            $query->where('inventory_id', $request->inventory_id);
        }

        // 🔍 Filter by reference type
        if ($request->filled('reference_type')) {
            $query->where('reference_type', $request->reference_type);
        }

        // 🔍 Filter by in / out
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // 🔍 Search by reference_id
        if ($request->filled('search')) {
            $query->where('reference_id', 'like', '%' . $request->search . '%');
        }

        // 📅 Date range filter
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('created_at', [
                $request->from_date . ' 00:00:00',
                $request->to_date . ' 23:59:59',
            ]);
        }

        // ⬇️ Latest first
        $transactions = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($transactions);
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

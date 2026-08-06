<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockTransactionResource;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class StockTransactionController extends Controller
{

    public function index(Request $request)
    {
        $query = StockTransactionController::build($request)
            ->select('stock_transactions.*')
            ->with([
                'inventory.product'
            ])
            ->orderByDesc('stock_transactions.created_at');

        $perPage = $request->get('per_page', 50);

        $stock_transactions = $query->paginate($perPage);

        return StockTransactionResource::collection($stock_transactions);
    }

    // public function index(Request $request)
    // {
    //     $query = StockTransaction::query()->with(["inventory.product"]);

    //     // Filter by inventory
    //     if ($request->filled("inventory_id")) {
    //         $query->where("inventory_id", $request->inventory_id);
    //     }

    //     // Filter by reference type
    //     if ($request->filled("reference_type")) {
    //         $query->where("reference_type", $request->reference_type);
    //     }

    //     // Filter by in / out
    //     if ($request->filled("type")) {
    //         $query->where("type", $request->type);
    //     }

    //     // Search by reference_id
    //     if ($request->filled("search")) {
    //         $query->where("reference_id", "like", "%" . $request->search . "%");
    //     }

    //     // Date range filter
    //     if ($request->filled("start_date") && $request->filled("end_date")) {
    //         $query->whereBetween("reference_date", [
    //             $request->start_date,
    //             $request->end_date,
    //         ]);
    //     } elseif ($request->filled("start_date")) {
    //         $query->whereDate("reference_date", ">=", $request->start_date);
    //     } elseif ($request->filled("end_date")) {
    //         $query->whereDate("reference_date", "<=", $request->end_date);
    //     }

    //     // ⬇Latest first
    //     $transactions = $query->orderBy("created_at", "desc")->get();

    //     return StockTransactionResource::collection($transactions);
    // }

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
        $transaction = StockTransaction::findOrFail($id);

        Log::info("Deleting stock transaction ID: {$transaction->id}, Type: {$transaction->type}, Inventory ID: {$transaction->inventory_id}, Quantity Change: {$transaction->quantity_change}");

        DB::beginTransaction();
        try {
            $inventory = $transaction->inventory;
            if ($inventory) {
                $change = (float) ($transaction->quantity_change ?? 0);

                if ($transaction->type === 'in') {
                    // reverse an "in" transaction by decreasing inventory
                    $inventory->qty = $inventory->qty - $change;
                } elseif ($transaction->type === 'out') {
                    // reverse an "out" transaction by increasing inventory
                    $inventory->qty = $inventory->qty + $change;
                }

                $inventory->save();
            }

            $transaction->delete();

            DB::commit();

            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting stock transaction: ' . $e->getMessage());
            return response()->json(['error' => 'Transaction could not be deleted'], 400);
        }
    }

    public static function build(Request $request)
    {
        return StockTransaction::query()
            ->when($request->filled('reference_id'), function ($q) use ($request) {
                $q->where('stock_transactions.reference_id', $request->reference_id);
            })

            ->when($request->filled('reference_type'), function ($q) use ($request) {
                $q->where('stock_transactions.reference_type', $request->reference_type);
            })

            ->when($request->filled('type'), function ($q) use ($request) {
                $q->where('stock_transactions.type', $request->type);
            })

            ->when($request->filled('product_search'), function ($q) use ($request) {

                $keyword = $request->product_search;

                $q->join('inventories', 'stock_transactions.inventory_id', '=', 'inventories.id')
                  ->join('products', 'inventories.product_id', '=', 'products.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('products.barcode', $keyword)
                          ->orWhere('products.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
                $q->whereBetween('stock_transactions.reference_date', [$request->start_date, $request->end_date]);
            })

            ->when($request->filled('start_date') && !$request->filled('end_date'), function ($q) use ($request) {
                $q->whereDate('stock_transactions.reference_date', '>=', $request->start_date);
            })

            ->when($request->filled('end_date') && !$request->filled('start_date'), function ($q) use ($request) {
                $q->whereDate('stock_transactions.reference_date', '<=', $request->end_date);
            })

            ->distinct();
    }

    public function export(Request $request)
    {
        $transactions = StockTransactionController::build($request)
            ->select('stock_transactions.*')
            ->with(['inventory.product'])
            ->orderByDesc('stock_transactions.reference_date')
            ->get();

        return StockTransactionResource::collection($transactions);
    }

    public function dashboard(Request $request)
    {
        $query = StockTransactionController::build($request);

        $stats = (clone $query)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN stock_transactions.type = 'in' THEN stock_transactions.quantity_change ELSE 0 END),0) as total_in,
                COALESCE(SUM(CASE WHEN stock_transactions.type = 'out' THEN stock_transactions.quantity_change ELSE 0 END),0) as total_out
            ")
            ->first();

        return response()->json($stats);
    }

}

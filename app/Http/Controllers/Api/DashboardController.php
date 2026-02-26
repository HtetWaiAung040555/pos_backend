<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\SaleDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        $saleQuery = Sale::query()->where('status_id', 7)->whereNull('void_at');  

        if ($warehouseId) {
            $saleQuery->where('warehouse_id', $warehouseId);
        }

        $totalRevenue = $saleQuery->sum('total_amount');
        $totalOrders  = $saleQuery->count();

        $averageSale = $totalOrders > 0
            ? round($totalRevenue / $totalOrders, 2)
            : 0;

        $topProduct = SaleDetail::select('product_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereHas('sale', function ($q) use ($warehouseId) {
                $q->whereNull('void_at');
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            })
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->with('product')
            ->first();

        return response()->json([
            'totalRevenue' => $totalRevenue,
            'averageSale'  => $averageSale,
            'totalOrders'  => $totalOrders,
            'topProduct'   => $topProduct?->product?->name,
        ]);
    }

    public function dailySales()
    {
        return Sale::selectRaw('DATE(sale_date) as date, SUM(total_amount) as total')
            ->whereNull('void_at')
            ->where('status_id', 7)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    public function monthlySales()
    {
        return Sale::selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(total_amount) as total")
            ->whereNull('void_at')
            ->where('status_id', 7)
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }

    public function yearlySales()
    {
        return Sale::selectRaw("YEAR(sale_date) as year, SUM(total_amount) as total")
            ->whereNull('void_at')
            ->where('status_id', 7)
            ->groupBy('year')
            ->orderBy('year')
            ->get();
    }

    public function paymentMethods(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        $query = Sale::query()
            ->select('payment_id', DB::raw('SUM(total_amount) as total_amount'), DB::raw('COUNT(*) as total_count'))
            ->where('status_id', 7)
            ->whereNull('void_at')
            ->groupBy('payment_id')
            ->with('paymentMethod');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $sales = $query->get();

        return response()->json(
            $sales->map(fn($sale) => [
                'payment_id'    => $sale->payment_id,
                'paymentMethod' => [
                    'id'   => $sale->paymentMethod->id,
                    'name' => $sale->paymentMethod->name,
                ],
                'total_amount'  => (float) $sale->total_amount,
                'total_count'   => (int) $sale->total_count,
            ])
        );
    }

    public function orders(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        $query = Sale::where('status_id', 7)
                    ->whereNull('void_at');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $sales = $query->orderBy('sale_date', 'desc')->with('paymentMethod')->get();

        return response()->json(
            $sales->map(function ($sale) {
                return [
                    'payment' => $sale->paymentMethod?->name,
                    'total'   => $sale->total_amount,
                    'sale_date' => $sale->sale_date->format('Y-m-d'),
                ];
            })
        );
    }

    public function stockLevels(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        $query = Inventory::select('product_id',DB::raw('SUM(qty) as total_qty'))
            ->where('qty', '!=', 0)
            ->whereNull('void_at')
            ->groupBy('product_id')
            ->with('product');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return response()->json(
            $query->get()->map(fn ($row) => [
                'product' => $row->product->name,
                'qty'     => (int) $row->total_qty,
            ])
        );
    }
}

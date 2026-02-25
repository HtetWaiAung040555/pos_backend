<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTopUpResource;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\WalletTopUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletsTopUpController extends Controller
{
    public function index(Request $request)
    {
        $query = WalletTopUp::with(["customer", "paymentMethod", "status", "createdBy", "updatedBy"]);

        if ($request->filled("customer_id")) {
            $query->where("customer_id", $request->customer_id);
        }

        if ($request->filled("status_id")) {
            $query->where("status_id", $request->status_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('pay_date', [
                $request->start_date,
                $request->end_date
            ]);
        } elseif ($request->filled('start_date')) {
            $query->where('pay_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->where('pay_date', '<=', $request->end_date);
        }

        return WalletTopUpResource::collection(
            $query->orderBy("pay_date", "desc")->get(),
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            "customer_id" => "required|exists:customers,id",
            "amount" => "required|numeric|min:0",
            "payment_id" => "nullable|exists:payment_methods,id",
            "remark" => "nullable|string|max:2000",
            "pay_date" => "required|date",
            "created_by" => "required|exists:users,id",
            "updated_by" => "nullable|exists:users,id",
        ]);

        DB::beginTransaction();
        try {
            $topup = WalletTopUp::create([
                "customer_id" => $request->customer_id,
                "amount" => $request->amount,
                "payment_id" => $request->payment_id,
                "status_id" => 7,
                "remark" => $request->remark,
                "pay_date" => $request->pay_date,
                "created_by" => $request->created_by,
                "updated_by" => $request->updated_by ?? $request->created_by,
            ]);

            // // Update customer balance
            // $customer = Customer::findOrFail($topup->customer_id);
            // $customer->balance += $topup->amount;
            // $customer->save();

            // // Create customer transaction
            // $req = CustomerTransaction::create([
            //     "customer_id" => $topup->customer_id,
            //     "reference_id"=> $topup->id,
            //     "type" => "top-up",
            //     "amount" => $topup->amount,
            //     "payment_id" => $topup->payment_id,
            //     "status_id" => $topup->status_id,
            //     "remark" => $topup->remark,
            //     "pay_date" => $topup->pay_date,
            //     "created_by" => $topup->created_by,
            //     "updated_by" => $topup->updated_by,
            // ]);

            DB::commit();

            return new WalletTopUpResource($topup->load(["customer", "paymentMethod", "createdBy", "updatedBy"]));

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "error" => "Failed to create balance transaction",
                    "details" => $e->getMessage(),
                ],500
            );
        }
    }

    public function show(string $id)
    {
        $topup = WalletTopUp::with(["customer", "paymentMethod", "createdBy", "updatedBy"])->findOrFail($id);
        return new WalletTopUpResource($topup);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            "customer_id" => "sometimes",
            "amount" => "sometimes|numeric|min:0",
            "payment_id" => "sometimes|exists:payment_methods,id",
            "remark" => "nullable|string|max:2000",
            "pay_date" => "sometimes|date",
            "updated_by" => "required|exists:users,id",
        ]);

        $topup = WalletTopUp::findOrFail($id);

        $oldCustomer = Customer::findOrFail($topup->customer_id);

        $oldCustomer->balance -= $topup->amount;
        $oldCustomer->save();

        DB::beginTransaction();
        try {
            $topup->fill($request->only(["customer_id", "amount", "payment_id", "remark", "pay_date"]));
            $topup->updated_by = $request->updated_by;
            $topup->save();

            // Update balance
            $customer = Customer::findOrFail($topup->customer_id);
            $customer->balance += $topup->amount;
            $customer->save();

            $customerTransaction = CustomerTransaction::where([
                'customer_id' => $topup->customer_id,
                'type' => 'top-up',
                'pay_date' => $topup->pay_date
            ])->first();

            if ($customerTransaction) {
                $customerTransaction->update([
                    "amount" => $topup->amount,
                    "payment_id" => $topup->payment_id,
                    "remark" => $topup->remark,
                    "pay_date" => $topup->pay_date,
                    "updated_by" => $topup->updated_by,
                ]);
            }

            DB::commit();

            return new WalletTopUpResource($topup->load(["customer", "paymentMethod", "createdBy", "updatedBy"]));

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "error" => "Failed to update balance transaction",
                    "details" => $e->getMessage(),
                ],500
            );
        }
    }

    public function destroy(Request $request, string $id)
    {
        $topup = WalletTopUp::findOrFail($id);

        DB::beginTransaction();
        try {
            // Update balance after delete
            $customer = Customer::findOrFail($topup->customer_id);
            $customer->balance -= $topup->amount;
            $customer->save();

            $topup->update([
                'status_id'  => 8,
                "updated_by" => $topup->updated_by,
            ]);

            // Update customer transaction
            $customerTransaction = CustomerTransaction::where([
                'customer_id' => $topup->customer_id,
                'type' => 'top-up',
                'pay_date' => $topup->pay_date
            ])->first();

            if ($customerTransaction) {
                $customerTransaction->update([
                    "status_id" => 8,
                    "updated_by" => $topup->updated_by,
                ]);
            }

            DB::commit();

            return response()->json([
                "message" => "Balance transaction deleted successfully",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "error" => "Cannot delete balance transaction",
                    "details" => $e->getMessage(),
                ],400
            );
        }
    }
}

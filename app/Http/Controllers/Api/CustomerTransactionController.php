<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTransactionResource;
use App\Models\CustomerTransaction;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerTransaction::with([
            "customer",
            "paymentMethod",
            "createdBy",
            "updatedBy",
        ]);

        if ($request->filled("customer_id")) {
            $query->where("customer_id", $request->customer_id);
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

        return CustomerTransactionResource::collection(
            $query->orderBy("id", "desc")->get(),
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
            $transaction = CustomerTransaction::create([
                "customer_id" => $request->customer_id,
                "type" => "top-up",
                "amount" => $request->amount,
                "payment_id" => $request->payment_id,
                "status_id" => 7,
                "remark" => $request->remark,
                "pay_date" => $request->pay_date,
                "created_by" => $request->created_by,
                "updated_by" => $request->updated_by ?? $request->created_by,
            ]);

            $customer = Customer::findOrFail($transaction->customer_id);

            $customer->balance += $transaction->amount;
            $customer->save();

            DB::commit();

            return new CustomerTransactionResource(
                $transaction->load([
                    "customer",
                    "paymentMethod",
                    "createdBy",
                    "updatedBy",
                ]),
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "error" => "Failed to create balance transaction",
                    "details" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function show($id)
    {
        $transaction = CustomerTransaction::with([
            "customer",
            "paymentMethod",
            "createdBy",
            "updatedBy",
        ])
            ->where("type", "top-up")
            ->findOrFail($id);

        return new CustomerTransactionResource($transaction);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            "customer_id" => "sometimes",
            "amount" => "sometimes|numeric|min:0",
            "payment_id" => "sometimes|exists:payment_methods,id",
            "remark" => "nullable|string|max:2000",
            "pay_date" => "sometimes|date",
            "updated_by" => "required|exists:users,id",
        ]);

        $transaction = CustomerTransaction::where("type", "top-up")->findOrFail(
            $id,
        );

        $oldCustomer = Customer::findOrFail($transaction->customer_id);

        $oldCustomer->balance -= $transaction->amount;
        $oldCustomer->save();

        DB::beginTransaction();
        try {
            $transaction->fill(
                $request->only([
                    "customer_id",
                    "amount",
                    "payment_id",
                    "remark",
                    "pay_date",
                ]),
            );
            $transaction->updated_by = $request->updated_by;
            $transaction->save();

            $customer = Customer::findOrFail($transaction->customer_id);

            $customer->balance += $transaction->amount;
            $customer->save();

            DB::commit();

            return new CustomerTransactionResource(
                $transaction->load([
                    "customer",
                    "paymentMethod",
                    "createdBy",
                    "updatedBy",
                ]),
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "error" => "Failed to update balance transaction",
                    "details" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function destroy($id)
    {
        $transaction = CustomerTransaction::where("type", "top-up")->findOrFail(
            $id,
        );
        $customerId = $transaction->customer_id;

        DB::beginTransaction();
        try {
            // Update balance after delete
            $customer = Customer::findOrFail($customerId);
            $customer->balance -= $transaction->amount;
            $customer->save();
            $transaction->delete();

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
                ],
                400,
            );
        }
    }

    // Update customer balance based on top-up transactions
    // private function updateCustomerBalance($customerId, $amount)
    // {
    //     $customer = Customer::findOrFail($customerId);

    //     $customer->balance += $amount;
    //     $customer->save();
    // }
}

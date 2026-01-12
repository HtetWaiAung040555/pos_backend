<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerTransactionResource;
use App\Models\CustomerTransaction;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CustomerTransactionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/customers_transactions',[
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ]);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            return response()->json([
                'message' => 'Success',
                'data' => $response->json('data')
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred during sync',
                'error'   => $e->getMessage()
            ], 500);
            
        }

        
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

            $cloudApiUrl = env('CLOUD_API_URL') . '/api/customers_transactions';
            $apiToken = env('CLOUD_API_TOKEN');
            $transaction = [
                "customer_id" => $request->customer_id,
                "type" => "top-up",
                "amount" => $request->amount,
                "payment_id" => $request->payment_id,
                "status_id" => 7,
                "remark" => $request->remark,
                "pay_date" => $request->pay_date,
                "created_by" => $request->created_by,
                "updated_by" => $request->updated_by ?? $request->created_by,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken
            ])->post($cloudApiUrl, $transaction);

            Log::info($response->json());

            $customer = Customer::findOrFail($request->customer_id);

            $customer->balance += $request->amount;
            $customer->save();

            DB::commit();

            return response()->json([
                'message' => 'Sync completed',
                'data' => $response->json('data')
            ],200);

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
            $transaction->delete();

            // Update balance after delete
            // $this->updateCustomerBalance($customerId);

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

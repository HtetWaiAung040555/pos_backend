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
use Illuminate\Validation\ValidationException;

class WalletsTopUpController extends Controller
{
    private const TYPE_DEPOSIT = 'deposit';
    private const TYPE_WITHDRAW = 'withdraw';
    private const TRANSACTION_TYPE_DEPOSIT = 'top-up';
    private const TRANSACTION_TYPE_WITHDRAW = 'withdraw';

    public function index(Request $request)
    {
        $query = WalletTopUp::with(["customer", "paymentMethod", "status", "createdBy", "updatedBy"]);

        if ($request->filled("customer_id")) {
            $query->where("customer_id", $request->customer_id);
        }

        if ($request->filled("type")) {
            $query->where("type", $request->type);
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
            "type" => "nullable|in:deposit,withdraw",
            "amount" => "required|numeric|min:0",
            "payment_id" => "nullable|exists:payment_methods,id",
            "remark" => "nullable|string|max:2000",
            "pay_date" => "required|date",
            "created_by" => "required|exists:users,id",
            "updated_by" => "nullable|exists:users,id",
        ]);

        DB::beginTransaction();
        try {
            $type = $this->walletType($request->type);
            $customer = Customer::findOrFail($request->customer_id);
            $this->ensureSufficientBalance($customer, $request->amount, $type);

            $topup = WalletTopUp::create([
                "id" => $request->id, // Will be auto-generated if not provided
                "customer_id" => $request->customer_id,
                "type" => $type,
                "amount" => $request->amount,
                "payment_id" => $request->payment_id,
                "status_id" => 7,
                "remark" => $request->remark,
                "pay_date" => $request->pay_date,
                "created_by" => $request->created_by,
                "updated_by" => $request->updated_by ?? $request->created_by,
            ]);

            // Update customer balance
            $this->applyWalletBalance($customer, $topup->amount, $type);
            $customer->save();

            // Create customer transaction
            CustomerTransaction::create([
                "customer_id" => $topup->customer_id,
                "reference_id"=> $topup->id,
                "type" => $this->customerTransactionType($type),
                "amount" => $topup->amount,
                "payment_id" => $topup->payment_id,
                "status_id" => $topup->status_id,
                "remark" => $topup->remark,
                "pay_date" => $topup->pay_date,
                "created_by" => $topup->created_by,
                "updated_by" => $topup->updated_by,
            ]);

            DB::commit();

            return new WalletTopUpResource($topup->load(["customer", "paymentMethod", "createdBy", "updatedBy"]));

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
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
            "type" => "sometimes|in:deposit,withdraw",
            "amount" => "sometimes|numeric|min:0",
            "payment_id" => "sometimes|exists:payment_methods,id",
            "remark" => "nullable|string|max:2000",
            "pay_date" => "sometimes|date",
            "updated_by" => "required|exists:users,id",
        ]);

        $topup = WalletTopUp::findOrFail($id);

        DB::beginTransaction();
        try {
            $oldCustomer = Customer::findOrFail($topup->customer_id);
            $this->reverseWalletBalance($oldCustomer, $topup->amount, $this->walletType($topup->type));
            $oldCustomer->save();

            $topup->fill($request->only(["customer_id", "type", "amount", "payment_id", "remark", "pay_date"]));
            $topup->updated_by = $request->updated_by;
            $topup->save();

            // Update balance
            $customer = Customer::findOrFail($topup->customer_id);
            $type = $this->walletType($topup->type);
            $this->ensureSufficientBalance($customer, $topup->amount, $type);
            $this->applyWalletBalance($customer, $topup->amount, $type);
            $customer->save();

            CustomerTransaction::updateOrCreate(
                ["reference_id" => $topup->id],
                [
                    "customer_id" => $topup->customer_id,
                    "type" => $this->customerTransactionType($type),
                    "amount" => $topup->amount,
                    "payment_id" => $topup->payment_id,
                    "status_id" => $topup->status_id,
                    "remark" => $topup->remark,
                    "pay_date" => $topup->pay_date,
                    "created_by" => $topup->created_by,
                    "updated_by" => $topup->updated_by,
                ]
            );

            DB::commit();

            return new WalletTopUpResource($topup->load(["customer", "paymentMethod", "createdBy", "updatedBy"]));

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
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
            $updatedBy = $request->updated_by ?? $request->user_id ?? $topup->updated_by;

            // Update balance after delete
            $customer = Customer::findOrFail($topup->customer_id);
            $this->reverseWalletBalance($customer, $topup->amount, $this->walletType($topup->type));
            $customer->save();

            $topup->update([
                'status_id'  => 8,
                "updated_by" => $updatedBy,
            ]);

            // Update customer transaction
            $customerTransaction = CustomerTransaction::where('reference_id', $topup->id)->first();

            if ($customerTransaction) {
                $customerTransaction->update([
                    "status_id" => 8,
                    "updated_by" => $updatedBy,
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

    private function walletType(?string $type): string
    {
        return $type ?: self::TYPE_DEPOSIT;
    }

    private function customerTransactionType(string $walletType): string
    {
        return $walletType === self::TYPE_WITHDRAW
            ? self::TRANSACTION_TYPE_WITHDRAW
            : self::TRANSACTION_TYPE_DEPOSIT;
    }

    private function applyWalletBalance(Customer $customer, $amount, string $type): void
    {
        if ($type === self::TYPE_WITHDRAW) {
            $customer->balance -= $amount;
            return;
        }

        $customer->balance += $amount;
    }

    private function reverseWalletBalance(Customer $customer, $amount, string $type): void
    {
        if ($type === self::TYPE_WITHDRAW) {
            $customer->balance += $amount;
            return;
        }

        $customer->balance -= $amount;
    }

    private function ensureSufficientBalance(Customer $customer, $amount, string $type): void
    {
        if ($type !== self::TYPE_WITHDRAW) {
            return;
        }

        if ((float) $customer->balance >= (float) $amount) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => 'Insufficient wallet balance for withdraw.',
        ]);
    }
}
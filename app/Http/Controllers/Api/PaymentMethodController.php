<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use App\Http\Resources\PaymentMethodResource;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $paymentMethods = PaymentMethod::with(['status', 'createdBy', 'updatedBy'])->get();
        return PaymentMethodResource::collection($paymentMethods);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'status_id' => 'required|exists:statuses,id',
            'is_default' => 'boolean',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $paymentMethod = PaymentMethod::create([
            'name' => $request->name,
            'status_id' => $request->status_id,
            'is_default' => $request->is_default ?? false,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);

        return new PaymentMethodResource($paymentMethod->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function show(string $id)
    {
        $paymentMethod = PaymentMethod::with(['status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new PaymentMethodResource($paymentMethod);
    }

    public function update(Request $request, string $id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'is_default' => 'boolean',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'status_id', 'is_default', 'updated_by']);
        $paymentMethod->update($data);

        return new PaymentMethodResource($paymentMethod->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            PaymentMethod::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Payment Method cannot be deleted'], 400);
        }
    }
}

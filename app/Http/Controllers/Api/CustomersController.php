<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomersController extends Controller
{
    public function index()
    {
        $customers = Customer::with(['status', 'createdBy', 'updatedBy'])->get();
        return CustomerResource::collection($customers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'id' => 'required|unique:customers,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'status_id' => 'required|exists:statuses,id',
            'is_default' => 'boolean',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $customer = Customer::create([
            'id' => $request->id,
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'status_id' => $request->status_id,
            'is_default' => $request->is_default ?? false,
            'balance' => $request->balance ?? 0,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);

        return new CustomerResource($customer->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function show(string $id)
    {
        $customer = Customer::with(['status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new CustomerResource($customer);
    }

    public function update(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'address' => 'sometimes|string|max:255',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'is_default' => 'sometimes|boolean',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'phone', 'address', 'status_id', 'is_default', 'updated_by']);
        $customer->update($data);

        return new CustomerResource($customer->fresh(['status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            Customer::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Customer cannot be deleted', 'details' => $e->getMessage()], 400);
        }
    }

    public function getLastId()
    {
        $lastCustomer = Customer::orderBy('created_at', 'desc')->first();
        return response()->json(['last_id' => $lastCustomer->id ?? null]);
    }
}

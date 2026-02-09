<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    public function syncFromCloud(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
                ->get(env('CLOUD_API_URL') . '/api/customers');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            $customers = $response->json('data');

            if (! is_array($customers)) {
                return response()->json([
                    'message' => 'Invalid customer data'
                ], 500);
            }

            foreach ($customers as $item) {

                Customer::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name'       => $item['name'],
                        'phone'      => $item['phone'],
                        'address'      => $item['address'],
                        'status_id'  => $item['status']['id'],
                        'is_default'      => $item['is_default'],
                        'balance'      => isset($item['balance']) ? (float) $item['balance'] : 0,
                        'created_by' => $item['created_by']['id'],
                        'updated_by' => $request->updated_by
                    ]
                );
            }

            return response()->json(['message' => 'success'], 200);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred during sync',
                'error'   => $e->getMessage()
            ], 500);
            
        }
    }

    // public function syncToCloud(Request $request)
    // {
    //     try {

    //         $customers = Customer::with(['status', 'createdBy', 'updatedBy'])->get();

    //         $errors = [];

    //         foreach ($customers as $customer) {

    //             $payload = [
    //                 'id'          => $customer->id,
    //                 'name'        => $customer->name,
    //                 'phone'       => $customer->phone,
    //                 'address'     => $customer->address,
    //                 'status_id'   => $customer->status->id ?? null,
    //                 'is_default'  => (bool) $customer->is_default,
    //                 'balance'     => (float) $customer->balance,
    //                 'created_by'  => $customer->createdBy->id ?? null,
    //                 'updated_by'  => $customer->updatedBy->id ?? null,
    //             ];

    //             $response = Http::withToken(env('CLOUD_API_TOKEN'))
    //                 ->post(env('CLOUD_API_URL') . '/api/customers', $payload);

    //             // If sync failed, add to errors
    //             if (!$response->successful()) {
    //                 $errors[] = [
    //                     'customer_id' => $customer->id,
    //                     'status'      => $response->status(),
    //                     'body'        => $response->body(),
    //                 ];
    //             }
    //         }

    //         if (!empty($errors)) {
    //             return response()->json([
    //                 'message' => 'Some customers failed to sync',
    //                 'errors'  => $errors
    //             ], 500);
    //         }

    //         return response()->json(['message' => 'All customers synced successfully'], 200);

    //     } catch (\Throwable $e) {
    //         return response()->json([
    //             'message' => 'An error occurred during syncToCloud',
    //             'error'   => $e->getMessage()
    //         ], 500);
    //     }
    // }

}
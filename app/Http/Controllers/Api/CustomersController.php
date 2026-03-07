<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    // public function syncFromCloud(Request $request)
    // {
        
    //     try {
    //         $response = Http::withToken(config('services.cloud.token'))
    //             ->get(config('services.cloud.url') . '/api/customers');

    //         if (! $response->successful()) {
    //             return response()->json([
    //                 'message' => 'Cloud API request failed',
    //                 'status'  => $response->status()
    //             ], 500);
    //         }

    //         $customers = $response->json('data');

    //         if (! is_array($customers)) {
    //             return response()->json([
    //                 'message' => 'Invalid customer data'
    //             ], 500);
    //         }

    //         foreach ($customers as $item) {

    //             $customer = Customer::updateOrCreate(
    //                 ['id' => $item['id']],
    //                 [
    //                     'name'       => $item['name'],
    //                     'phone'      => $item['phone'],
    //                     'address'      => $item['address'],
    //                     'status_id'  => $item['status']['id'],
    //                     'is_default'      => $item['is_default'],
    //                     'balance'      => (float) $item['balance'],
    //                     'created_at' => $item['created_at'],
    //                     'created_by' => $item['created_by']['id'],
    //                     'updated_by' => $request->updated_by
    //                 ]
    //             );

    //         }

    //         return response()->json(['message' => 'success'], 200);

    //     } catch (\Exception $e) {

    //         return response()->json([
    //             'message' => 'An error occurred during sync',
    //             'error'   => $e->getMessage()
    //         ], 500);

    //     }
    // }

    public function syncFromCloud(Request $request)
    {
        try {

            $response = Http::withToken(config('services.cloud.token'))
                ->timeout(20)
                ->retry(3, 200)
                ->get(config('services.cloud.url') . '/api/customers');

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            $customers = $response->json('data');

            if (!is_array($customers)) {
                return response()->json([
                    'message' => 'Invalid customer data'
                ], 500);
            }

            $rows = [];

            foreach ($customers as $item) {

                if (!isset($item['id'])) {
                    continue;
                }

                $rows[] = [
                    'id'         => $item['id'],
                    'name'       => $item['name'] ?? null,
                    'phone'      => $item['phone'] ?? null,
                    'address'    => $item['address'] ?? null,
                    'status_id'  => $item['status']['id'] ?? null,
                    'is_default' => $item['is_default'] ?? false,
                    'balance'    => (float) ($item['balance'] ?? 0),
                    'created_by' => $item['created_by']['id'] ?? null,
                    'created_at' => $item['created_at'] ?? now(),
                    'updated_by' => $request->updated_by,
                    'updated_at' => now(),
                ];
            }

            if (!empty($rows)) {

                Customer::upsert(
                    $rows,
                    ['id'], // unique key
                    [
                        'name',
                        'phone',
                        'address',
                        'status_id',
                        'is_default',
                        'balance',
                        'updated_by',
                        'updated_at'
                    ]
                );
            }

            return response()->json([
                'message' => 'Customers synced successfully',
                'count'   => count($rows)
            ]);

        } catch (\Throwable $e) {

            Log::error('Customer sync failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Customer sync failed',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use App\Http\Resources\PaymentMethodResource;
use Illuminate\Support\Facades\Http;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $paymentMethods = PaymentMethod::with(['status', 'createdBy', 'updatedBy'])->get();
        return PaymentMethodResource::collection($paymentMethods);
    }

    // public function store(Request $request)
    // {
    //     $request->validate([
    //         'name' => 'required|string|max:255',
    //         'status_id' => 'required|exists:statuses,id',
    //         'is_default' => 'boolean',
    //         'created_by' => 'required|exists:users,id',
    //         'updated_by' => 'nullable|exists:users,id'
    //     ]);

    //     $paymentMethod = PaymentMethod::create([
    //         'name' => $request->name,
    //         'status_id' => $request->status_id,
    //         'is_default' => $request->is_default ?? false,
    //         'created_by' => $request->created_by,
    //         'updated_by' => $request->updated_by ?? $request->created_by
    //     ]);

    //     return new PaymentMethodResource($paymentMethod->fresh(['status', 'createdBy', 'updatedBy']));
    // }
    public function store(Request $request)
    {

        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/payment_methods');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json() as $item) {
                PaymentMethod::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'status_id' => $item['status']['id'],
                        'is_default' => $item['is_default'],
                        'created_by' => $item['created_by']['id'],
                        'created_at' => $item['created_at'],
                        'updated_by' => $request->updated_by ?? $request->created_by
                    ]
                );
            }
            return response()->json(['message' => 'success'],200);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred during sync',
                'error'   => $e->getMessage()
            ], 500);

        }
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

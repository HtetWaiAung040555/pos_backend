<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Counter;
use App\Http\Resources\CounterResource;
use Illuminate\Support\Facades\Http;

class CountersController extends Controller
{
    public function index()
    {
        $counters = Counter::with(['branch', 'status', 'createdBy', 'updatedBy'])->get();
        return CounterResource::collection($counters);
    }

    public function store(Request $request)
    {

        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/counters');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json() as $item) {
                Counter::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'desc' => $item['desc'],
                        'branch_id' => $item['branch']['id'],
                        'status_id' => $item['status']['id'],
                        'created_by' => $item['created_by']['id'],
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
        $counter = Counter::with(['branch', 'status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new CounterResource($counter);
    }

    public function update(Request $request, string $id)
    {
        $counter = Counter::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'desc' => 'nullable|string|max:1000',
            'branch_id' => 'sometimes|required|exists:branches,id',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'desc', 'branch_id', 'status_id', 'updated_by']);
        $counter->update($data);

        return new CounterResource($counter->fresh(['branch', 'status', 'createdBy', 'updatedBy']));
    }

    public function destroy(string $id)
    {
        try {
            Counter::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Counter cannot be deleted'], 400);
        }
    }
    
}

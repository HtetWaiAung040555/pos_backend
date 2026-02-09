<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class UnitsController extends Controller
{
    public function index()
    {
        $units = Unit::with(['status','createdBy', 'updatedBy'])->get();
        return UnitResource::collection($units);
    }


    public function store(Request $request)
    {

        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/units');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json('data') as $item) {
                Unit::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'phone' => $item['phone'],
                        'location' => $item['location'],
                        'warehouse_id' => $item['warehouse']['id'],
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
        $unit = Unit::with(['status', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new UnitResource($unit);
    }


    public function update(Request $request, string $id)
    {
        $unit = Unit::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $data = $request->only(['name', 'status_id', 'updated_by']);

        $unit->update($data);

        return new UnitResource($unit->fresh(['status', 'createdBy', 'updatedBy']));
    }

    
    public function destroy(string $id)
    {
        try {
            Unit::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Branch cannot be deleted'], 400);
        }
    }
}

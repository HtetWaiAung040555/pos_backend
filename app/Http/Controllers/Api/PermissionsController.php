<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PermissionsController extends Controller
{

    public function index()
    {
        $permissions = Permission::with(['createdBy', 'updatedBy'])->get();
        return PermissionResource::collection($permissions);
    }


    public function store(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/permissions');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json() as $item) {
                Permission::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'action' => $item['action'],
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
        $permission = Permission::with(['createdBy', 'updatedBy', 'roles'])->findOrFail($id);
        return new PermissionResource($permission);
    }


    public function update(Request $request, string $id)
    {
        $permission = Permission::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'action' => 'sometimes|required|string|max:1000',
            'updated_by' => 'required|exists:users,id'
        ]);

        $data = $request->only(['name', 'action', 'updated_by']);
        $permission->update($data);

        return new PermissionResource($permission->fresh(['createdBy', 'updatedBy', 'roles']));
    }


    public function destroy(string $id)
    {
        try {
            $permission = Permission::findOrFail($id);
            $permission->roles()->detach(); // fix typo
            $permission->delete();

            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Permission cannot be deleted'], 400);
        }
    }
}

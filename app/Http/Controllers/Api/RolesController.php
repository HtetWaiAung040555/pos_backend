<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;
use App\Models\Role;
use Illuminate\Support\Facades\Http;

class RolesController extends Controller
{

    public function index()
    {
        $roles = Role::with(['status', 'createdBy', 'updatedBy', 'permissions'])->get();
        return RoleResource::collection($roles);
    }


    public function store(Request $request)
    {

        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
            ->get(env('CLOUD_API_URL') . '/api/roles');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            foreach ($response->json('data') as $item) {
                $role = Role::updateOrCreate(
                    ['id' => $item['id']],
                    [
                        'name' => $item['name'],
                        'desc' => $item['desc'],
                        'status_id' => $item['status']['id'],
                        'created_by' => $item['created_by']['id'],
                        'updated_by' => $request->updated_by ?? $request->created_by
                    ]
                );

                if (!empty($item['permissions'])) {
                    $permissionIds = collect($item['permissions'])->pluck('id')->toArray();
                    $role->permissions()->sync($permissionIds);
                }
                
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
        $role = Role::with(['status', 'createdBy', 'updatedBy', 'permissions'])->findOrFail($id);
        return new RoleResource($role);
    }


    public function update(Request $request, string $id)
    {
        $role = Role::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'desc' => 'nullable|string|max:1000',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'updated_by' => 'nullable|exists:users,id',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        $data = $request->only(['name', 'desc', 'status_id', 'updated_by']);
        $role->update($data);

        if ($request->has('permissions')) {
            $role->permissions()->sync($request->permissions);
        }

        return new RoleResource($role->fresh(['status', 'createdBy', 'updatedBy', 'permissions']));
    }


    public function destroy(string $id)
    {
        try {
            $role = Role::findOrFail($id);
            $role->permissions()->detach();
            $role->delete();

            return response()->json(['message' => 'Deleted'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Role cannot be deleted'], 400);
        }
    }

    // public function assignPermission(Role $role, Permission $permission)
    // {
    //     $role->permissions()->attach($permission->id);
    //     return response()->json([
    //         'message' => 'Permission assigned successfully',
    //         'role' => $role->load('permissions')
    //     ]);
    // }

    // public function removePermission(Role $role, Permission $permission)
    // {
    //     $role->permissions()->detach($permission->id);
    //     return response()->json([
    //         'message' => 'Permission removed successfully',
    //         'role' => $role->load('permissions')
    //     ]);
    // }


}

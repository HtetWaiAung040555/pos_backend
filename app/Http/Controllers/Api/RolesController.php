<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;

use App\Models\Role;
use App\Models\Permission;

class RolesController extends Controller
{

    public function index()
    {
        $roles = Role::with(['status', 'createdBy', 'updatedBy', 'permissions'])->get();
        return RoleResource::collection($roles);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'desc' => 'nullable|string|max:1000',
            'status_id' => 'required|exists:statuses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role = Role::create([
            'name' => $request->name,
            'desc' => $request->desc,
            'status_id' => $request->status_id,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by,
        ]);

        if ($request->has('permissions')) {
            $role->permissions()->attach($request->permissions);
        }

        return new RoleResource($role->fresh(['status', 'createdBy', 'updatedBy', 'permissions']));
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
            'permissions.*' => 'exists:permissions,id',
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

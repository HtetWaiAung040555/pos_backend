<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;

class PermissionsController extends Controller
{

    public function index()
    {
        $permissions = Permission::with(['createdBy', 'updatedBy'])->get();
        return PermissionResource::collection($permissions);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'action' => 'required|string|max:1000',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        $permission = Permission::create([
            'name' => $request->name,
            'action' => $request->action,
            'created_by' => $request->created_by,
            'updated_by' => $request->updated_by ?? $request->created_by
        ]);

        return new PermissionResource($permission->fresh(['createdBy', 'updatedBy', 'roles']));
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

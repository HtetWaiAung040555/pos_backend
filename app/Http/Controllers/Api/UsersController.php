<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UsersController extends Controller
{
    public function index()
    {
        // $users = User::with(['branch', 'counter', 'status', 'createdBy', 'updatedBy'])->get();

        $users = User::with(["branch","counter","status","createdBy","updatedBy",])
            ->where("status_id", "!=", 3)
            ->get();

        return UserResource::collection($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            "name" => "required|string|max:255",
            "email" => "required|string|email|max:255|unique:users",
            "password" => "required|string|min:8",
            "branch_id" => "nullable|exists:branches,id",
            "counter_id" => "nullable|exists:counters,id",
            "status_id" => "exists:statuses,id",
            "roles" => "nullable|array|exists:roles,id",
            "created_by" => "nullable|exists:users,id",
        ]);

        $user = User::create([
            "name" => $request->name,
            "email" => $request->email,
            "password" => bcrypt($request->password),
            "branch_id" => $request->branch_id,
            "counter_id" => $request->counter_id,
            "role_id" => $request->role_id,
            "status_id" => $request->status_id,
            "created_by" => $request->created_by,
            "updated_by" => $request->updated_by ?? $request->created_by,
        ]);

        return new UserResource(
            $user->fresh([
                "branch",
                "counter",
                "role",
                "status",
                "createdBy",
                "updatedBy",
            ]),
        );
    }

    public function show($id)
    {
        // $user = User::with(['branch','counter','status','role','createdBy','updatedBy'])->findOrFail($id);

        $user = User::with([
            "branch",
            "counter",
            "status",
            "role",
            "createdBy",
            "updatedBy",
        ])
            ->where("status_id", "!=", 3) // exclude disabled
            ->findOrFail($id);

        return new UserResource($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            "name" => "sometimes|string|max:255",
            "email" =>
                "sometimes|string|email|max:255|unique:users,email," . $id,
            "password" => "sometimes|string|min:8",
            "branch_id" => "exists:branches,id",
            "counter_id" => "nullable|exists:counters,id",
            "role_id" => "sometimes|exists:roles,id",
            "status_id" => "exists:statuses,id",
            "updated_by" => "required|exists:users,id",
        ]);

        $data = $request->only([
            "name",
            "email",
            "password",
            "branch_id",
            "counter_id",
            "role_id",
            "status_id",
        ]);

        if ($request->password) {
            $data["password"] = bcrypt($request->password);
        }

        $user->update($data);

        return new UserResource(
            $user->fresh([
                "branch",
                "counter",
                "role",
                "status",
                "createdBy",
                "updatedBy",
            ]),
        );
    }

    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);

            $user->status_id = 3;
            $user->save();

            return response()->json(
                ["message" => "User deactivated successfully"],
                200,
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(
                ["error" => "User is referenced, cannot delete"],
                400,
            );
        }
    }

    public function syncFromCloud(Request $request)
    {
        try {
            $response = Http::withToken(env('CLOUD_API_TOKEN'))
                ->get(env('CLOUD_API_URL') . '/api/users');

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Cloud API request failed',
                    'status'  => $response->status()
                ], 500);
            }

            $users = $response->json('data');

            if (! is_array($users)) {
                return response()->json([
                    'message' => 'Invalid customer data'
                ], 500);
            }

            foreach ($users as $user) {

                User::updateOrCreate(
                    ['id' => $user['id']],
                    [
                        'name'       => $user['name'],
                        'email'      => $user['email'],
                        'password'      => $user['password'],
                        'branch_id'      => $user['branch']['id'],
                        'counter_id'      => $user['counter']['id'],
                        'role_id'      => $user['role']['id'],
                        'status_id'  => $user['status']['id'],
                        'created_by' => $user['created_by']['id'],
                        'created_at' => $user['created_at'],
                        'updated_by' => $request->updated_by
                    ]
                );
            }

            $userList = User::with(["branch","counter","status","createdBy","updatedBy",])
                ->where("status_id", "!=", 3)
                ->get();

            $allUsers = UserResource::collection($userList);

            return response()->json([
                'message' => 'success',
                'data' => $allUsers
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'An error occurred during sync',
                'error'   => $e->getMessage()
            ], 500);

        }
    }

    // public function assignRole(User $user, Role $role)
    // {
    //     $user->roles()->attach($role->id);
    //     return response()->json([
    //         'message' => 'Role assigned successfully',
    //         'user' => $user->load('roles')
    //     ]);
    // }

    // public function removeRole(User $user, Role $role)
    // {
    //     $user->roles()->detach($role->id);
    //     return response()->json([
    //         'message' => 'Role removed successfully',
    //         'user' => $user->load('roles')
    //     ]);
    // }

    // public function hasPermission(User $user, Permission $permission)
    // {
    //     $hasPermission = $user->roles()
    //         ->with('permissions')
    //         ->get()
    //         ->pluck('permissions')
    //         ->flatten()
    //         ->contains('id', $permission->id);

    //     return response()->json([
    //         'user_id' => $user->id,
    //         'permission_id' => $permission->id,
    //         'has_permission' => $hasPermission
    //     ]);
    // }
}

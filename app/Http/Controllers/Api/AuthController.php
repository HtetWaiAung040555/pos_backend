<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    // Login
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
                'isSuccess' => false
            ], 401);
        }

        // Create a Sanctum token
        $token = $user->createToken('api-token')->plainTextToken;

        $userData = new UserResource($user->fresh(['branch', 'counter', 'role' , 'status', 'createdBy', 'updatedBy']));

        return response()->json([
            'message' => 'Login successful',
            'user' => $userData,
            'token' => $token,
            'isSuccess' => true
        ]);
    }

    // Logout
    public function logout(Request $request)
    {
        // Delete the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
            'isSuccess' => true
        ]);
    }
}

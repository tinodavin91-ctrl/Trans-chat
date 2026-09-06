<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($this->firestore->findUserByEmail($request->email)) {
            return response()->json([
                'errors' => ['email' => ['The email has already been taken.']],
            ], 422);
        }

        $userData = $this->firestore->create('users', [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'hide_online_status' => false,
            'remember_token' => null,
            'email_verified_at' => null,
        ]);

        $user = $this->firestore->toUser($userData);
        $token = $this->firestore->createToken($user);

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $this->firestore->findUserByEmail($request->email);

        if (! $user || ! Hash::check($request->password, $user->getAuthPassword())) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $this->firestore->createToken($user);

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $this->firestore->deleteCurrentToken($request->user());

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->getAuthPassword())) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $updated = $this->firestore->update('users', $user->id, [
            'password' => Hash::make($request->password),
        ]);

        $this->firestore->deleteOtherTokens($user);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => $this->firestore->toUser($updated),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function index(Request $request)
    {
        $currentUserId = $request->user()->id;

        $users = $this->firestore->all('users')
            ->reject(fn (array $user) => ($user['id'] ?? null) === $currentUserId)
            ->map(fn (array $user) => [
                'id' => $user['id'],
                'name' => $user['name'] ?? null,
                'email' => $user['email'] ?? null,
            ])
            ->values();

        return response()->json($users);
    }

    public function updatePrivacy(Request $request)
    {
        $request->validate([
            'hide_online_status' => 'required|boolean',
        ]);

        $updated = $this->firestore->update('users', $request->user()->id, [
            'hide_online_status' => (bool) $request->hide_online_status,
        ]);

        return response()->json($this->firestore->toUser($updated));
    }
}

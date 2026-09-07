<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::where('id', '!=', $request->user()->id)
            ->select('id', 'name', 'email')
            ->get();

        return response()->json($users);
    }

    public function updatePrivacy(Request $request)
    {
        $request->validate([
            'hide_online_status' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->update([
            'hide_online_status' => (bool) $request->hide_online_status,
        ]);

        return response()->json($user);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\User;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function store(Request $request, string $userId)
    {
        $blockerId = $request->user()->id;

        if ((string) $userId === (string) $blockerId) {
            return response()->json(['message' => 'You cannot block yourself.'], 422);
        }

        if (! User::find($userId)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        Block::firstOrCreate([
            'blocker_id' => $blockerId,
            'blocked_id' => $userId,
        ]);

        return response()->json(['blocked' => true]);
    }

    public function destroy(Request $request, string $userId)
    {
        Block::where('blocker_id', $request->user()->id)
            ->where('blocked_id', $userId)
            ->delete();

        return response()->json(['blocked' => false]);
    }

    public function show(Request $request, string $userId)
    {
        $blocked = Block::where('blocker_id', $request->user()->id)
            ->where('blocked_id', $userId)
            ->exists();

        return response()->json(['blocked' => $blocked]);
    }
}

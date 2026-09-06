<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function store(Request $request, string $userId)
    {
        $blockerId = $request->user()->id;

        if ($userId === $blockerId) {
            return response()->json(['message' => 'You cannot block yourself.'], 422);
        }

        if (! $this->firestore->find('users', $userId)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $existing = $this->firestore->where('blocks', 'blocker_id', '=', $blockerId)
            ->first(fn (array $block) => ($block['blocked_id'] ?? null) === $userId);

        if (! $existing) {
            $this->firestore->create('blocks', [
                'blocker_id' => $blockerId,
                'blocked_id' => $userId,
            ]);
        }

        return response()->json(['blocked' => true]);
    }

    public function destroy(Request $request, string $userId)
    {
        $blocks = $this->firestore->where('blocks', 'blocker_id', '=', $request->user()->id)
            ->filter(fn (array $block) => ($block['blocked_id'] ?? null) === $userId);

        foreach ($blocks as $block) {
            $this->firestore->delete('blocks', $block['id']);
        }

        return response()->json(['blocked' => false]);
    }

    public function show(Request $request, string $userId)
    {
        $blocked = $this->firestore->where('blocks', 'blocker_id', '=', $request->user()->id)
            ->contains(fn (array $block) => ($block['blocked_id'] ?? null) === $userId);

        return response()->json(['blocked' => $blocked]);
    }
}

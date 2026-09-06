<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StickerController extends Controller
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function index()
    {
        $packs = $this->firestore->all('sticker_packs')->map(function (array $pack) {
            $pack['stickers'] = $this->firestore->where('stickers', 'sticker_pack_id', '=', $pack['id'])
                ->map(fn (array $sticker) => $this->withImageUrl($sticker))
                ->values()
                ->all();

            return $pack;
        })->values();

        return response()->json($packs);
    }

    public function mine(Request $request)
    {
        $stickers = $this->firestore->where('stickers', 'user_id', '=', $request->user()->id)
            ->filter(fn (array $sticker) => empty($sticker['sticker_pack_id']))
            ->sortByDesc('created_at')
            ->map(fn (array $sticker) => $this->withImageUrl($sticker))
            ->values();

        return response()->json($stickers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image',
            'name' => 'nullable|string|max:255',
        ]);

        $path = $request->file('image')->store('stickers', 'public');

        $sticker = $this->firestore->create('stickers', [
            'user_id' => $request->user()->id,
            'sticker_pack_id' => null,
            'name' => $request->name,
            'image_path' => $path,
        ]);

        return response()->json($this->withImageUrl($sticker), 201);
    }

    public function destroy(Request $request, string $sticker)
    {
        $document = $this->firestore->get('stickers', $sticker);

        if (($document['user_id'] ?? null) !== $request->user()->id) {
            abort(403);
        }

        if (! empty($document['image_path'])) {
            Storage::disk('public')->delete($document['image_path']);
        }

        $this->firestore->delete('stickers', $sticker);

        return response()->json(['message' => 'Sticker deleted']);
    }

    public function image(string $sticker)
    {
        $document = $this->firestore->get('stickers', $sticker);
        $path = storage_path('app/public/'.($document['image_path'] ?? ''));

        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    }

    private function withImageUrl(array $sticker): array
    {
        $sticker['image_url'] = ! empty($sticker['id'])
            ? url('/api/stickers/'.$sticker['id'].'/image')
            : null;

        return $sticker;
    }
}

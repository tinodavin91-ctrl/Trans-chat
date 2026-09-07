<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sticker;
use App\Models\StickerPack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StickerController extends Controller
{
    public function index()
    {
        $packs = StickerPack::with('stickers')->get();

        return response()->json($packs);
    }

    public function mine(Request $request)
    {
        $stickers = Sticker::where('user_id', $request->user()->id)
            ->whereNull('sticker_pack_id')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($stickers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image',
            'name' => 'nullable|string|max:255',
        ]);

        $path = $request->file('image')->store('stickers', 'public');

        $sticker = Sticker::create([
            'user_id' => $request->user()->id,
            'sticker_pack_id' => null,
            'name' => $request->name,
            'image_path' => $path,
        ]);

        return response()->json($sticker, 201);
    }

    public function destroy(Request $request, Sticker $sticker)
    {
        if ($sticker->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($sticker->image_path) {
            Storage::disk('public')->delete($sticker->image_path);
        }

        $sticker->delete();

        return response()->json(['message' => 'Sticker deleted']);
    }

    public function image(Sticker $sticker)
    {
        $path = storage_path('app/public/'.$sticker->image_path);

        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path);
    }
}

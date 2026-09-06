<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sticker extends Model
{
    protected $fillable = ['sticker_pack_id', 'user_id', 'name', 'image_path'];

    protected $appends = ['image_url'];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(StickerPack::class, 'sticker_pack_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

   public function getImageUrlAttribute()
{
    return $this->image_path ? url('/api/stickers/' . $this->id . '/image') : null;
}
}

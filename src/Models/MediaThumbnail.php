<?php

namespace lhaamed\MediaModule\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use lhaamed\MediaModule\Traits\HasFileManager;

class MediaThumbnail extends Model
{
    use HasFileManager;

    /**
     * file_name is the thumbnail path relative to the disk root,
     * e.g. "2026-09/thumbnails/mania-service-logo-400x300.png".
     */
    protected $fillable = [
        'media_id',
        'file_name',
        'width',
        'height',
        'size',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleted(function (MediaThumbnail $thumbnail) {
            $disk = $thumbnail->disk;

            if ($disk) {
                Storage::disk($disk)->delete($thumbnail->file_name);
            }
        });
    }

    // RELATIONS

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    // GETTERS (proxied from the parent media, so a thumbnail can be used wherever a Media is)

    protected function disk(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->media?->disk,
        );
    }

    protected function alt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->media?->alt,
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->media?->description,
        );
    }

    protected function dimensions(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => "{$attributes['width']}x{$attributes['height']}",
        );
    }

    public function storagePath(): string
    {
        return $this->file_name;
    }
}

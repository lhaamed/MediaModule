<?php

namespace lhaamed\MediaModule\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use lhaamed\MediaModule\Traits\hasFileManager;

class MediaThumbnail extends Model
{
    use hasFileManager;

    protected $fillable = [
        'media_id',
        'width',
        'height',
    ];

    /**
     * Indicates if the model should be timestamped.
     * @var bool
     */
    public $timestamps = false;

    protected static function booted()
    {
        self::deleting(function (MediaThumbnail $mediaThumbnail) {
            if ($mediaThumbnail->fileExists()) unlink(public_path($mediaThumbnail->pathToFile()));
        });
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    // GETTER


    protected function width(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $value,
        );
    }

    protected function height(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $value,
        );
    }

    protected function size(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => "{$this->width}-{$this->height}",
        );
    }

    protected function fileName(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $this->media->file_name,
        );
    }

    protected function mimeType(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $this->media->mime_type,
        );
    }

    protected function fileFullName(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => "{$this->file_name}-{$this->size}.{$this->mime_type}",
        );
    }

    protected function disk(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $this->media->disk,
        );
    }

    protected function alt(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $this->media->alt,
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $this->media->description,
        );
    }

    public function dateDirectoryFormat(): string
    {
        return $this->media->dateDirectoryFormat();
    }


    public function pathToDirectory(): string
    {
        return Storage::disk($this->disk)->path('/') . $this->dateDirectoryFormat() . '/thumbnails';
    }
    public function DirectoryURL(): string
    {
        return Storage::disk($this->disk)->url('/') . $this->dateDirectoryFormat() . '/thumbnails';
    }
}

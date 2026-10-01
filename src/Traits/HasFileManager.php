<?php

namespace lhaamed\MediaModule\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * File helpers shared by Media and MediaThumbnail.
 *
 * The using model must provide:
 *  - a `disk` attribute
 *  - storagePath(): the file path relative to the disk root
 */
trait HasFileManager
{


    protected ?array $imageSizeCache = null;

    abstract public function storagePath(): string;

    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->storagePath());
    }

    public function fileURL(): string
    {
        return Storage::disk($this->disk)->url($this->storagePath());
    }

    public function url(): string
    {
        return $this->fileExists() ? $this->fileURL() : static::getFeaturedImagePlaceholder();
    }

    public static function getFeaturedImagePlaceholder(): string
    {
        return asset(config('media.placeholder', 'assets/default-images/default-gallery-photo.png'));
    }

    /**
     * Absolute filesystem path. Local disks only: throws on drivers without path() (e.g. s3).
     */
    public function pathToFile(): string
    {
        return Storage::disk($this->disk)->path($this->storagePath());
    }

    protected function sizeForHumans(): Attribute
    {
        return Attribute::get(fn () => Number::fileSize($this->size, precision: 1));
    }

    public function getFileWidth(): ?int
    {
        return $this->imageSize()['width'] ?? null;
    }

    public function getFileHeight(): ?int
    {
        return $this->imageSize()['height'] ?? null;
    }

    /**
     * ['width' => ..., 'height' => ...] یا null اگه فایل تصویر نباشه یا وجود نداشته باشه.
     */
    protected function imageSize(): ?array
    {
        // فایل غیرتصویری (مثلاً ویدیوی بزرگ) رو کامل توی حافظه لود نکن
        if (!str_starts_with($this->mime_type ?? 'image/', 'image/')) {
            return null;
        }

        if ($this->imageSizeCache === null) {
            $contents = Storage::disk($this->disk)->get($this->storagePath());
            $size = $contents ? @getimagesizefromstring($contents) : false;

            $this->imageSizeCache = $size ? ['width' => $size[0], 'height' => $size[1]] : [];
        }

        return $this->imageSizeCache ?: null;
    }
}

<?php


namespace lhaamed\MediaModule\Traits;

use Illuminate\Support\Facades\Storage;

trait hasFileManager
{
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
}

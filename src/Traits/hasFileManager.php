<?php


namespace lhaamed\MediaModule\Traits;

use Illuminate\Support\Facades\Storage;

trait hasFileManager
{

    // GETTER

    public function pathToFile(): string
    {
        return $this->pathToDirectory() . '/' . $this->file_full_name;
    }

    public function fileURL(): string
    {
        return $this->DirectoryURL() . '/' . $this->file_full_name;
    }

    public function pathToDirectory(): string
    {
        return Storage::disk($this->disk)->path('/') . $this->dateDirectoryFormat();
    }

    public function DirectoryURL(): string
    {
        return Storage::disk($this->disk)->url('/') . $this->dateDirectoryFormat();
    }

    public function url(): string
    {
        if ($this->fileExists())
            return $this->fileURL();
        return $this->getFeaturedImagePlaceholder();
    }

    public static function getFeaturedImagePlaceholder(): string
    {
        return asset('assets/default-images/default-gallery-photo.png');
    }

    public function getFileWidth()
    {
        return getimagesize($this->pathToFile())[0];
    }

    public function getFileHeight()
    {
        return getimagesize($this->pathToFile())[1];
    }


    // STATUS CHECK

    public function fileExists(): bool
    {
        return file_exists($this->pathToFile());
    }


}

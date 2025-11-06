<?php

namespace lhaamed\MediaModule\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use lhaamed\MediaModule\Models\Media;
use lhaamed\MediaModule\Models\MediaThumbnail;

class MediaService
{

    /**
     * @throws Exception
     */
    public function upload(UploadedFile $file, array $options = [], $disk = null)
    {
        if (is_null($disk)) $disk = config('MediaModule.disk');
        $media = new Media();

        if (in_array($disk, config('MediaModule.disks'))) {
            return DB::transaction(function () use ($options, $disk, $file, $media) {
                $media->mime_type = self::extractFileMimeType($file);
                $media->disk = $disk;
                $media->file_name = self::generateUniqueNameInDisk($file, $disk);
                if (array_key_exists('key', $options)) $media->key = $options['key'];

                if (self::moveFileToDisk($file, $media->file_full_name, $disk)) {
                    $media->save();
                    File::ensureDirectoryExists($media->pathToDirectory() . '/thumbnails');
                    return $media;
                } else throw new Exception('something bad happened in moving file to directory.', 403);
            });
        } else
            return throw new Exception('the chosen disc is unavailable.', 403);
    }

    public function replace(UploadedFile $file, Media $media, array $options = [])
    {
        return DB::transaction(function () use ($options, $file, $media) {
            $media->mime_type = self::extractFileMimeType($file);
            if (array_key_exists('key', $options)) $media->key = $options['key'];

            if ($media->fileExists()) {
                unlink($media->pathToFile());
            }

            if (self::moveFileToDisk($file, $media->file_full_name, $media->disk)) {
                $media->save();
                File::ensureDirectoryExists($media->pathToDirectory() . '/thumbnails');

                foreach ($media->thumbnails as $thumbnail) {
                    $this->deleteThumbnail($thumbnail);
                }
                return $media;
            } else throw new Exception('something bad happened in moving file to directory.', 403);
        });
    }

    private static function generateUniqueNameInDisk(UploadedFile $file, $disk): string
    {
        $originalName = self::slugifyFileOriginalName($file);
        $generatedName = $originalName;
        $allDiskMedias = Media::all()->where('disk', $disk);
        if ($allDiskMedias->where('file_name', $originalName)->count()) {
            $index = 1;
            do {
                $name = "{$originalName}-{$index}";
                $index++;
            } while ($allDiskMedias->where('file_name', $name)->count());
            $generatedName = $name;
        }
        return $generatedName;
    }

    private static function extractFileOriginalName(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalName());
    }

    private static function extractFileMimeType(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension());
    }

    private static function slugifyFileOriginalName(UploadedFile $file): string
    {
        $file_original_name = self::extractFileOriginalName($file);
        $file_mime_type = self::extractFileMimeType($file);
        return Str::slug(basename($file_original_name, ".{$file_mime_type}"));

    }

    private static function moveFileToDisk(UploadedFile $file, string $file_full_name, string $disk): false|string
    {
        return DB::transaction(function () use ($file, $file_full_name, $disk) {
            $result = $file->storeAs(date('Y-m'), $file_full_name, $disk);
            self::calculateOccupiedStorage();
            return $result;
        });
    }

    public function generateThumbnail(Media $media, string $width, $height = null)
    {
        if (is_null($height)) $flag = 'scale';
        else $flag = 'resize';
        File::ensureDirectoryExists($media->pathToDirectory() . '/thumbnails');
        return DB::transaction(function () use ($flag, $media, $width, $height) {
            $thumbnail = new MediaThumbnail();
            $thumbnail->media_id = $media->id;
            if ($media->getFileWidth() >= $width) {
                // create image manager with desired driver
                $ImageManager = new ImageManager(new Driver());
                $image = $ImageManager->read($media->pathToFile());
                if ($flag == 'scale') {
                    // if this is a scale operation. first I have to scale to image to find final height. then store the thumbnail record in database.
                    if (!$media->thumbnails()->where('width', $width)->exists()) {
                        // first the thumbnail record must not be exists and the width must be smaller than original photo
                        $image->scale(width: $width); // 400 x 300
                        $thumbnail->width = $image->width();
                        $thumbnail->height = $image->height();
                        $thumbnail->save();
                        if (!$thumbnail->fileExists()) {
                            $image->save($thumbnail->pathToFile());
                            if (!$thumbnail->fileExists()) {
                                throw new Exception('failed to save thumbnail file');
                            }
                        }
                        return $thumbnail;
                    } else return $media->thumbnails()->where('width', $width)->first();
                } elseif ($flag == 'resize') {
                    // if this is a resize operation. both width and height are indicated and there is nothing to doubt. we can create the db record and then resize the image.
                    if (!$media->thumbnails()->where('width', $width)->where('height', $height)->exists() && $media->getFileHeight() >= $height) {
                        // first the thumbnail record must not be exists and the width must be smaller than original photo

                        $image->resize($width, $height); // 400 x 300
                        $thumbnail->width = $image->width();
                        $thumbnail->height = $image->height();
                        $thumbnail->save();
                        if (!$thumbnail->fileExists()) {
                            $image->save($thumbnail->pathToFile());
                            if (!$thumbnail->fileExists()) {
                                throw new Exception('failed to save thumbnail file');
                            }
                        }
                        return $thumbnail;
                    } else $media->thumbnails()->where('width', $width)->where('height', $height)->first();
                } else throw new Exception(403, 'unrecognized operation found.');
            } else throw new Exception('width is larger than original file.');
            return $thumbnail;
        });
    }

    public function deleteMedia(Media $media)
    {
        return DB::transaction(function () use ($media) {
            foreach ($media->thumbnails as $thumbnail) {
                self::deleteThumbnail($thumbnail);
            }
            if ($media->fileExists()) {
                unlink($media->pathToFile());
                $this->calculateOccupiedStorage();
            }
            return $media->delete();
        });
    }

    public function deleteThumbnail(MediaThumbnail $mediaThumbnail)
    {
        return DB::transaction(function () use ($mediaThumbnail) {
            if ($mediaThumbnail->fileExists())
                unlink($mediaThumbnail->pathToFile());
            return $mediaThumbnail->delete();
        });
    }

    public function repairThumbnail(MediaThumbnail $mediaThumbnail): void
    {
        // this line helps us to retrieve the latest updates of Media record.
        $mediaThumbnail->load('media');

        // create image manager with desired driver
        File::ensureDirectoryExists($mediaThumbnail->media->pathToDirectory() . '/thumbnails');
        $ImageManager = new ImageManager(new Driver());

        $image = $ImageManager->read($mediaThumbnail->media->pathToFile());
        if ($mediaThumbnail->height == null) {
            $image->scale(width: $mediaThumbnail->width);
        } else {
            $image->resize($mediaThumbnail->width, $mediaThumbnail->height); // 400 x 300
        }
        if (!$mediaThumbnail->fileExists()) {
            $image->save($mediaThumbnail->pathToFile());
        }
    }

    public function renameMedia(Media $media, string $new_name): bool
    {
        $old_name = $media->file_name;
        // first of all we need to delete all thumbnails that exist for this photo.
        return DB::transaction(function () use ($new_name, $media) {
            foreach ($media->thumbnails as $thumbnail) {
                if ($thumbnail->fileExists())
                    unlink($thumbnail->pathToFile());
            }
            // then we need to rename the file.
            if ($media->fileExists()) {
                $old_file_path = $media->dateDirectoryFormat() . '/' . $media->file_full_name;
                $new_file_path = $media->dateDirectoryFormat() . '/' . $new_name . '.' . $media->mime_type;
                if (Storage::disk($media->disk)->move($old_file_path, $new_file_path)) {
                    return true;
                } else {
                    throw new Exception('changing file named failed.');
                }
            } else throw new Exception('the file is missing. so the changing process aborted.');
        });
    }

    /**
     * @throws \Throwable
     */
    private static function calculateOccupiedStorage(string|null $path = null): void
    {
        /*$flags = \FilesystemIterator::SKIP_DOTS;

        try {
            if ($path === null) {
                $path = dirname($_SERVER['DOCUMENT_ROOT']);
            }

            $size = 0;

            if (is_dir($path)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        @new \RecursiveDirectoryIterator($path, $flags),
                        function ($current, $key, $iterator) {
                            try {
                                // فقط مسیرهایی که قابل دسترسی هستن
                                return $current->isDir() || $current->isFile();
                            } catch (\Throwable) {
                                // مسیر غیرمجاز، نادیده گرفته می‌شود
                                return false;
                            }
                        }
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($it as $file) {
                    try {
                        // اگر سمبلینک هست و نمی‌خوای دنبال بشه:
                        if ($file->isFile() && !$file->isLink()) {
                            $size += $file->getSize();
                        }
                    } catch (\Throwable $e) {
                        // مسیر غیرمجاز یا خطا، نادیده گرفته میشه
                        continue;
                    }
                }
            }

            Setting::getByKey('disk_occupied_size')->handleUpdate(['value' => $size]);

        } catch (\Throwable $exception) {
            Alert::defaultToastError($exception->getMessage());
            throw $exception;
        }*/
    }

}

<?php

namespace lhaamed\MediaModule\Services;

use App\Facades\Alert;
use App\Models\Setting;
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
    public function upload(UploadedFile $file, array $options = [], ?string $disk = null): Media
    {
        $disk ??= config('media.disk');

        if (!in_array($disk, config('media.disks'), true)) {
            throw new Exception('the chosen disk is unavailable.', 403);
        }
        if (!$file->isValid()) {
            throw new Exception($file->getErrorMessage(), 422);
        }

        // mime comes from file content, never from the client.
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';

        $media = new Media([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_name' => self::generateUniqueName($file->getClientOriginalName(), $disk),
            'extension' => self::resolveExtension($file, $mimeType),
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath()),
            'disk' => $disk,
            'alt' => $options['alt'] ?? null,
            'description' => $options['description'] ?? null,
        ]);

        // set before storing: the directory (Y-m) is derived from created_at,
        // so the file and the record can never disagree at a month boundary.
        $media->created_at = now();

        $stored = $file->storeAs($media->dateDirectoryFormat(), $media->file_full_name, ['disk' => $disk]);
        if ($stored === false) {
            throw new Exception('something bad happened in moving file to directory.', 500);
        }

        try {
            $media->save();
        } catch (Throwable $e) {
            // do not leave an orphan file behind (e.g. unique violation from a concurrent upload).
            Storage::disk($disk)->delete($stored);
            throw $e;
        }

        return $media;
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

    /**
     * slug of the original name without extension, e.g. "Mania Service Logo.PNG" => "mania-service-logo".
     * appends -1, -2, ... when the name is already taken on this disk.
     */
    private static function generateUniqueName(string $originalName, string $disk): string
    {
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $base = rtrim(Str::limit($base, 200, ''), '-') ?: 'file'; // non-latin names can slug to ''

        // slug only contains [a-z0-9-], so it is safe inside LIKE.
        $taken = Media::where('disk', $disk)
            ->where('file_name', 'like', $base . '%')
            ->pluck('file_name')
            ->all();

        if (!in_array($base, $taken, true)) return $base;

        $i = 1;
        while (in_array("{$base}-{$i}", $taken, true)) $i++;
        return "{$base}-{$i}";
    }

    /**
     * keep the client extension only when it is valid for the detected mime type
     * (so "shell.php" with image content is stored as .png), then apply the deny list.
     *
     * @throws Exception
     */
    private static function resolveExtension(UploadedFile $file, string $mimeType): string
    {
        $client = strtolower($file->getClientOriginalExtension());
        $known = MimeTypes::getDefault()->getExtensions($mimeType);

        if ($client !== '' && (empty($known) || in_array($client, $known, true))) {
            $extension = $client;
        } else {
            $extension = $known[0] ?? 'bin';
        }

        $extension = substr(preg_replace('/[^a-z0-9]/', '', $extension), 0, 20) ?: 'bin';

        if (in_array($extension, config('media.blocked_extensions', []), true)) {
            throw new Exception("files of type .{$extension} are not allowed.", 422);
        }

        return $extension;
    }

    private static function extractFileMimeType(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension());
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
        $flags = \FilesystemIterator::SKIP_DOTS;

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
        }
    }

}

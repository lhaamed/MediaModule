<?php

namespace lhaamed\MediaModule\Services;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use lhaamed\MediaModule\Models\Media;
use lhaamed\MediaModule\Models\MediaThumbnail;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class MediaService
{
    // UPLOAD / REPLACE

    /**
     * @param array{alt?: string, description?: string} $options
     * @throws Exception
     */
    public function upload(UploadedFile $file, array $options = [], ?string $disk = null): Media
    {
        $disk ??= config('media.disk');

        if (!in_array($disk, config('media.disks'), true)) {
            throw new Exception('the chosen disk is unavailable.', 403);
        }

        $media = new Media([
            ...self::describe($file),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_name' => self::generateUniqueName($file->getClientOriginalName(), $disk),
            'disk' => $disk,
            'alt' => $options['alt'] ?? null,
            'description' => $options['description'] ?? null,
        ]);

        // set before storing: the directory (Y-m) is derived from created_at,
        // so the file and the record can never disagree at a month boundary.
        $media->created_at = now();
        $path = $media->storagePath();

        if ($file->storeAs(dirname($path), basename($path), ['disk' => $disk]) === false) {
            throw new Exception('something bad happened in moving file to directory.', 500);
        }

        try {
            $media->save();
        } catch (Throwable $e) {
            // do not leave an orphan file behind (e.g. unique violation from a concurrent upload).
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        return $media;
    }

    /**
     * Replaces the content of a media. file_name (and so the URL base) stays, the extension may change.
     *
     * @param array{alt?: string|null, description?: string|null} $options
     * @throws Exception
     */
    public function replace(UploadedFile $file, Media $media, array $options = []): Media
    {
        $oldPath = $media->storagePath();

        $media->fill([
            ...self::describe($file),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
        ]);
        if (array_key_exists('alt', $options)) $media->alt = $options['alt'];
        if (array_key_exists('description', $options)) $media->description = $options['description'];

        $newPath = $media->storagePath();

        DB::transaction(function () use ($media, $file, $newPath) {
            $media->save();

            if ($file->storeAs(dirname($newPath), basename($newPath), ['disk' => $media->disk]) === false) {
                throw new Exception('something bad happened in moving file to directory.', 500);
            }
        });

        // old file goes only after the new one is safely stored.
        if ($oldPath !== $newPath) {
            Storage::disk($media->disk)->delete($oldPath);
        }

        // existing thumbnails were built from the old content.
        $media->thumbnails->each->delete();

        return $media;
    }

    // THUMBNAILS

    /**
     * Returns null when no thumbnail is possible: unsupported type, or the requested size
     * is larger than the original (no upscaling).
     *
     * Without $height the image is scaled proportionally to $width; with it, it is resized to exactly that size.
     *
     * @throws Exception
     */
    public function generateThumbnail(Media $media, int $width, ?int $height = null): ?MediaThumbnail
    {
        if ($width < 1 || ($height !== null && $height < 1)) {
            throw new Exception('thumbnail size must be positive.', 422);
        }
        if (!$media->isThumbnailable()) {
            return null;
        }

        $image = self::readImage($media);

        if ($width > $image->width() || ($height !== null && $height > $image->height())) {
            return null;
        }

        $height === null
            ? $image->scale(width: $width)
            : $image->resize($width, $height);

        $realWidth = $image->width();
        $realHeight = $image->height();

        // scale mode can land on a size that already exists.
        $existing = $media->thumbnails()->where('width', $realWidth)->where('height', $realHeight)->first();
        if ($existing) {
            return $existing;
        }

        $path = self::thumbnailPath($media, $realWidth, $realHeight);
        $contents = $image->encodeByMediaType($media->mime_type)->toString();
        Storage::disk($media->disk)->put($path, $contents);

        try {
            return MediaThumbnail::create([
                'media_id' => $media->id,
                'file_name' => $path,
                'width' => $realWidth,
                'height' => $realHeight,
                'size' => strlen($contents),
            ]);
        } catch (QueryException $e) {
            // a concurrent request created the same thumbnail first.
            $existing = $media->thumbnails()->where('width', $realWidth)->where('height', $realHeight)->first();
            if ($existing) {
                return $existing;
            }

            Storage::disk($media->disk)->delete($path);
            throw $e;
        }
    }

    /**
     * Rebuilds a missing thumbnail file from the original.
     *
     * @throws Exception
     */
    public function repairThumbnail(MediaThumbnail $thumbnail): void
    {
        $thumbnail->load('media');
        $media = $thumbnail->media;

        if (!$media || !$media->isThumbnailable()) {
            throw new Exception('the thumbnail cannot be repaired.', 422);
        }

        $contents = self::readImage($media)
            ->resize($thumbnail->width, $thumbnail->height)
            ->encodeByMediaType($media->mime_type)
            ->toString();

        Storage::disk($media->disk)->put($thumbnail->file_name, $contents);
        $thumbnail->update(['size' => strlen($contents)]);
    }

    // RENAME / DELETE

    /**
     * Renames the stored file. The name is slugged, so the final name is returned.
     * The model is saved here, together with the file move.
     *
     * @throws Exception
     */
    public function renameMedia(Media $media, string $newName): string
    {
        $name = self::slugify($newName);

        if ($name === $media->file_name) {
            return $name;
        }
        if (Media::where('disk', $media->disk)->where('file_name', $name)->exists()) {
            throw new Exception("the name '{$name}' is already taken.", 422);
        }

        $disk = Storage::disk($media->disk);
        $oldName = $media->file_name;
        $oldPath = $media->storagePath();

        try {
            return DB::transaction(function () use ($media, $name, $disk, $oldPath) {
                $media->file_name = $name;
                $media->save();
                $newPath = $media->storagePath();

                if (!$disk->exists($oldPath)) {
                    throw new Exception('the file is missing, so the rename was aborted.', 404);
                }
                if ($disk->exists($newPath)) {
                    throw new Exception('a file with this name already exists on the disk.', 409);
                }
                if (!$disk->move($oldPath, $newPath)) {
                    throw new Exception('changing the file name failed.', 500);
                }

                return $name;
            });
        } catch (Throwable $e) {
            // the transaction rolled the row back; put the in-memory model back too.
            $media->setAttribute('file_name', $oldName);
            $media->syncOriginalAttribute('file_name');
            throw $e;
        }
    }

    public function deleteMedia(Media $media): bool
    {
        return (bool) $media->delete();
    }

    public function deleteThumbnail(MediaThumbnail $thumbnail): bool
    {
        return (bool) $thumbnail->delete();
    }

    // HELPERS

    /**
     * mime comes from the file content, never from the client.
     *
     * @return array{mime_type: string, extension: string, size: int, hash: string}
     * @throws Exception
     */
    private static function describe(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new Exception($file->getErrorMessage(), 422);
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';

        return [
            'mime_type' => $mimeType,
            'extension' => self::resolveExtension($file, $mimeType),
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    private static function slugify(string $name): string
    {
        // non-latin names can slug to '' so fall back to "file".
        return rtrim(Str::limit(Str::slug($name), 200, ''), '-') ?: 'file';
    }

    /**
     * slug of the original name without extension, e.g. "Mania Service Logo.PNG" => "mania-service-logo".
     * appends -1, -2, ... when the name is already taken on this disk.
     */
    private static function generateUniqueName(string $originalName, string $disk): string
    {
        $base = self::slugify(pathinfo($originalName, PATHINFO_FILENAME));

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

    private static function thumbnailPath(Media $media, int $width, int $height): string
    {
        return "{$media->dateDirectoryFormat()}/thumbnails/{$media->file_name}-{$width}x{$height}.{$media->extension}";
    }

    /**
     * @throws Exception
     */
    private static function readImage(Media $media): ImageInterface
    {
        $contents = Storage::disk($media->disk)->get($media->storagePath());

        if ($contents === null) {
            throw new Exception('the file is missing.', 404);
        }

        return (new ImageManager(new Driver()))->read($contents);
    }
}

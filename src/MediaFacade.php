<?php

namespace lhaamed\MediaModule;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use lhaamed\MediaModule\Models\Media;
use lhaamed\MediaModule\Models\MediaThumbnail;
use lhaamed\MediaModule\Services\MediaService;

/**
 * @method static Media upload(UploadedFile $file, array $options = [], ?string $disk = null)
 * @method static Media replace(UploadedFile $file, Media $media, array $options = [])
 * @method static MediaThumbnail|null generateThumbnail(Media $media, int $width, ?int $height = null)
 * @method static void repairThumbnail(MediaThumbnail $thumbnail)
 * @method static string renameMedia(Media $media, string $newName)
 * @method static bool deleteMedia(Media $media)
 * @method static bool deleteThumbnail(MediaThumbnail $thumbnail)
 *
 * @see MediaService
 */
class MediaFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaService::class;
    }
}

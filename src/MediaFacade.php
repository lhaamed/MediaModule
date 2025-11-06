<?php

namespace lhaamed\MediaModule;

use lhaamed\MediaModule\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use lhaamed\MediaModule\Models\Media;
use lhaamed\MediaModule\Models\MediaThumbnail;


/**
 * @method static mixed upload(UploadedFile $file, array $options = [], $disk = 'media')
 * @method static mixed replace(UploadedFile $file, Media $media, array $options = [])
 * @method static mixed generateThumbnail(Media $photo, string $width, $height = null)
 * @method static mixed deletePhoto(Media $photo)
 * @method static mixed deleteThumbnail(MediaThumbnail $photoThumbnail)
 * @method static mixed repairThumbnail(MediaThumbnail $photoThumbnail)
 * @method static mixed renameMedia(Media $photo, string $new_name)
 *
 */
class MediaFacade extends Facade
{

    protected static function getFacadeAccessor(): string
    {
        return MediaService::class;
    }

}

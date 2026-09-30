<?php

namespace lhaamed\MediaModule;

use lhaamed\MediaModule\Models\Media;
use lhaamed\MediaModule\Models\MediaThumbnail;

class MediaModels
{
    /** @return class-string<Media> */
    public static function media(): string
    {
        return config('media.models.media') ?: Media::class;
    }

    /** @return class-string<MediaThumbnail> */
    public static function thumbnail(): string
    {
        return config('media.models.thumbnail') ?: MediaThumbnail::class;
    }
}

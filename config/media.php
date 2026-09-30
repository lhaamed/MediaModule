<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default disk
    |--------------------------------------------------------------------------
    | Disk used by MediaFacade::upload() when none is given.
    */
    'disk' => env('MEDIA_DISK', 'media'),

    /*
    |--------------------------------------------------------------------------
    | Allowed disks
    |--------------------------------------------------------------------------
    | Disks new uploads may go to (comma separated in .env). Existing media
    | always use the disk stored on their own row.
    */
    'disks' => array_map('trim', explode(',', env('MEDIA_DISKS', 'media'))),

    /*
    |--------------------------------------------------------------------------
    | Blocked extensions
    |--------------------------------------------------------------------------
    | Uploads whose final extension is listed here are rejected.
    | Any other type is accepted.
    */
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
        'htaccess', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'msi', 'dll', 'bat', 'cmd', 'com', 'scr', 'jar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnailable MIME types
    |--------------------------------------------------------------------------
    | Only these types get thumbnails (they must be readable by the image
    | driver). Everything else returns the media itself from ->thumbnail().
    */
    'thumbnailable_mimes' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Placeholder
    |--------------------------------------------------------------------------
    | Path (relative to public/) returned by ->url() when the file is missing.
    */
    'placeholder' => 'vendor/media/placeholders/unknown.png',
    'upload_placeholder' => 'vendor/media/placeholders/upload.png',

    'placeholders' => [
        // اول پسوند بررسی می‌شه
        'extensions' => [
            'csv'  => 'vendor/media/placeholders/csv.png',
            'doc'  => 'vendor/media/placeholders/doc.png',
            'docx' => 'vendor/media/placeholders/docx.png',
            'gif'  => 'vendor/media/placeholders/gif.png',
            'jpg'  => 'vendor/media/placeholders/jpg.png',
            'json'  => 'vendor/media/placeholders/json.png',
            'mp3'  => 'vendor/media/placeholders/mp3.png',
            'mp4'  => 'vendor/media/placeholders/mp4.png',
            'pdf'  => 'vendor/media/placeholders/pdf.png',
            'png'  => 'vendor/media/placeholders/png.png',
            'rar'  => 'vendor/media/placeholders/rar.png',
            'txt'  => 'vendor/media/placeholders/txt.png',
            'webm'  => 'vendor/media/placeholders/webm.png',
            'webp'  => 'vendor/media/placeholders/webp.png',
            'xls'  => 'vendor/media/placeholders/xls.png',
            'xlsx' => 'vendor/media/placeholders/xlsx.png',
            'zip'  => 'vendor/media/placeholders/zip.png',
        ],

        // بعد mime دقیق یا گروه mime (video/*)
        'mimes' => [
            'video/*' => 'vendor/media/placeholders/mp4.png',
            'audio/*' => 'vendor/media/placeholders/mp3.png',
            'image/*' => 'vendor/media/placeholders/png.png', // عکسی که فایلش گم شده
        ],
    ],



    /*
    |--------------------------------------------------------------------------
    | Publish Models
    |--------------------------------------------------------------------------
    | referencing publish models.
    */

    'models' => [
        'media' => \lhaamed\MediaModule\Models\Media::class,
        'thumbnail' => \lhaamed\MediaModule\Models\MediaThumbnail::class,
    ],

];

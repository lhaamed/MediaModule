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
    'placeholder' => 'assets/default-images/default-gallery-photo.png',

    'placeholders' => [
        // اول پسوند بررسی می‌شه
        'extensions' => [
            'pdf'  => 'assets/default-images/types/pdf.png',
            'doc'  => 'assets/default-images/types/word.png',
            'docx' => 'assets/default-images/types/word.png',
            'xls'  => 'assets/default-images/types/excel.png',
            'xlsx' => 'assets/default-images/types/excel.png',
            'zip'  => 'assets/default-images/types/archive.png',
            'rar'  => 'assets/default-images/types/archive.png',
            '7z'   => 'assets/default-images/types/archive.png',
            'exe'  => 'assets/default-images/types/executable.png',
        ],

        // بعد mime دقیق یا گروه mime (video/*)
        'mimes' => [
            'video/*' => 'assets/default-images/types/video.png',
            'audio/*' => 'assets/default-images/types/audio.png',
            'image/*' => 'assets/default-images/types/image.png', // عکسی که فایلش گم شده
        ],
    ],

];

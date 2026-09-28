<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | مشخص می‌کنه که فایل‌ها کجا ذخیره بشن.
    | می‌تونی هر دیسکی که توی config/filesystems.php تعریف شده استفاده کنی
    | (مثلاً: local, public, s3).
    |
    */

    'disk' => env('MEDIAMODULE_DISK', 'public'),
    'disks' => explode(',',env('MEDIAMODULE_DISKS', 'public')),

    /*
    |--------------------------------------------------------------------------
    | Base Directory
    |--------------------------------------------------------------------------
    |
    | مسیر پایه‌ای که همه فایل‌های این پکیج توی اون ذخیره می‌شن.
    | می‌تونی تغییرش بدی تا توی یک فولدر خاص نگه‌داری بشن.
    |
    */

    'base_directory' => env('MEDIAMODULE_BASE_DIR', 'lhaamed'),

    /*
    |--------------------------------------------------------------------------
    | Blocked Extensions
    |--------------------------------------------------------------------------
    |
    | Uploads whose final extension is in this list are rejected.
    | Any other mime type is accepted.
    |
    */

    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
        'htaccess', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'msi', 'dll', 'bat', 'cmd', 'com', 'scr', 'jar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnailable mimes
    |--------------------------------------------------------------------------
    |
    | Uploads whose final extension is in this list are rejected.
    | Any other mime type is accepted.
    |
    */

    'thumbnailable_mimes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ],

];

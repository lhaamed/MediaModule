<div align="center">

# MediaModule

**File and media management for Laravel: uploads, polymorphic attachments and on-demand thumbnails.**

![PHP](https://img.shields.io/badge/PHP-%5E8.1-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-FF2D20?logo=laravel&logoColor=white)
![License](https://img.shields.io/github/license/lhaamed/MediaModule)
![Release](https://img.shields.io/github/v/tag/lhaamed/MediaModule?label=release)

</div>

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [Usage](#usage)
- [Thumbnails](#thumbnails)
- [API reference](#api-reference)
- [Upgrading from v1](#upgrading-from-v1)
- [Security](#security)
- [Customization](#customization)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Upload any file type.** The MIME type is detected from the file content, not from the client.
- **Polymorphic attachments.** Attach media to any Eloquent model, grouped by *collection* (`featured_image`, `gallery`, ...) and ordered.
- **Thumbnails on demand.** Generated the first time they are requested, for a configurable list of MIME types, and rebuilt automatically if the file goes missing.
- **Built on Laravel filesystem disks.** Paths are relative to the disk, so files are not tied to a hard-coded location.
- **Shared-hosting friendly.** The default `media` disk writes straight into `public/uploads/media`, so `storage:link` is not needed.
- **Safe upload defaults.** Extensions are validated against the detected content type, dangerous extensions are blocked, and a SHA-256 hash is stored for every file.
- **Upgrade path from v1.** A migration and an Artisan command convert existing data in place.

## Requirements

| | |
|---|---|
| PHP | 8.1 or higher, with `ext-gd` and `ext-fileinfo` |
| Laravel | 10, 11 or 12 |
| Image library | `intervention/image` ^3.0 (installed automatically) |

## Installation

The package is installed straight from GitHub. Add the repository to your project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/lhaamed/MediaModule" }
]
```

Then require it:

```bash
# tagged release (recommended)
composer require lhaamed/media-module:^1.0

# or the latest commit on the main branch
composer require lhaamed/media-module:dev-main
```

The service provider is registered through Laravel's package auto-discovery. No manual `psr-4` entry or provider registration is needed.

Run the migrations:

```bash
php artisan migrate
```

This creates the `media`, `mediaables` and `media_thumbnails` tables. Package migrations are loaded from the package itself, so publishing them is optional.

> **`SSL_ERROR_SYSCALL` when connecting to `api.github.com`?**
> Composer uses the GitHub API to read repository metadata. If your network blocks it, add `"no-api": true` to the repository entry and install with `--prefer-source`.

## Configuration

The package config is merged automatically, so nothing has to be published. Set the disks in `.env`:

```env
MEDIA_DISK=media
MEDIA_DISKS=media
```

| Variable | Description | Default |
|---|---|---|
| `MEDIA_DISK` | Disk used for new uploads. | `media` |
| `MEDIA_DISKS` | Comma-separated list of disks uploads are allowed on. | the value of `MEDIA_DISK` |

If your application does not define a disk named `media`, the package defines one for you: files are stored in `public/uploads/media` and served from `APP_URL/uploads/media`. If you define your own `media` disk, yours is used.

Existing records always use the disk stored on their own row, whatever the current setting is.

### Options

Publish the config file only if you need to change these values:

```bash
php artisan vendor:publish --tag=media-config
```

| Key | Description |
|---|---|
| `disk` | Default disk for new uploads. |
| `disks` | Disks that uploads are allowed on. |
| `blocked_extensions` | Extensions that are rejected on upload (`php`, `phtml`, `phar`, `exe`, `sh`, ...). Every other type is accepted. |
| `thumbnailable_mimes` | MIME types that get thumbnails (`image/jpeg`, `image/png`, `image/gif`, `image/webp`). |
| `placeholder` | Image path, relative to `public/`, returned by `url()` when a file is missing. |

> Config values are merged one level deep. If you publish the file and set `blocked_extensions`, your list replaces the default list instead of extending it. Keep only the keys you want to override.

To copy the migrations into your project instead of loading them from the package:

```bash
php artisan vendor:publish --tag=media-migrations
```

## Quick start

```php
use lhaamed\MediaModule\MediaFacade;
use lhaamed\MediaModule\Traits\HasMedia;

// 1. make a model able to hold media
class Category extends Model
{
    use HasMedia;
}

// 2. upload and attach
$media = MediaFacade::upload($request->file('image'));
$category->attachMedia($media, 'featured_image');

// 3. display a thumbnail (falls back to the placeholder image)
<img src="{{ $category->thumbnailUrl('featured_image', 350) }}">
```

## Usage

### Uploading

```php
$media = MediaFacade::upload($request->file('file'));

// with alt text, description and an explicit disk
$media = MediaFacade::upload($file, [
    'alt' => 'Company logo',
    'description' => 'Used in the site header',
], 'media');
```

Files are stored as `Y-m/{file_name}.{extension}` on the disk. For example, `Mania Logo.PNG` uploaded in September 2026 becomes `2026-09/mania-logo.png`.

| Field | Value |
|---|---|
| `original_name` | The name exactly as the client sent it. |
| `file_name` | Slug of the original name, without extension (`mania-logo`). |
| `extension` | File extension (`png`). |
| `mime_type` | Detected MIME type (`image/png`). |
| `size` | Size in bytes. |
| `hash` | SHA-256 of the content. |
| `disk` | Disk the file is stored on. |
| `alt`, `description` | Optional text. |
| `uploaded_by` | Authenticated user id, when there is one. |

`upload()` throws an `Exception` when the disk is not allowed (`403`), the file is invalid or its extension is blocked (`422`), or the file cannot be stored (`500`).

### Attaching media to models

Add the `HasMedia` trait to any model:

```php
use lhaamed\MediaModule\Traits\HasMedia;

class Article extends Model
{
    use HasMedia;
}
```

```php
$article->attachMedia($media, 'featured_image');
$article->attachMedia($media, 'gallery', order: 2);

$article->mediaIn('featured_image')->first();
$article->mediaIn('gallery')->get();

$article->detachMedia($media, 'gallery');
```

- A *collection* is the role a file plays for that model. When omitted it is `default`.
- `attachMedia()` adds an attachment and never replaces one. For a single-file collection, detach the old file first:
  `$article->mediaIn('featured_image')->detach();`
- Attaching the same media to the same model and collection twice is ignored.
- When a model is deleted its attachments are removed. The `Media` records and files are kept.

### Displaying

```php
$media->url();                       // file URL, or the placeholder when the file is missing
$media->thumbnail(350)->url();       // width 350, proportional height
$media->thumbnail(350, 350)->url();  // exactly 350 x 350
$article->thumbnailUrl('featured_image', 350);
```

`thumbnailUrl()` returns the placeholder when the model has no media in that collection.

### Managing media

```php
MediaFacade::replace($newFile, $media);        // new content, same file_name
MediaFacade::renameMedia($media, 'new-name');  // slugs the name and moves the file
$media->handleUpdate(['alt' => 'New alt', 'description' => null]);
$media->delete();                              // removes the thumbnails and the file too
```

`replace()` stores the new file before removing the old one, and removes the old thumbnails, since

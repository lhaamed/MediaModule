<?php

namespace lhaamed\MediaModule\Models;

use App\Traits\EssentialTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use lhaamed\MediaModule\MediaFacade;
use lhaamed\MediaModule\Traits\HasFileManager;
use Throwable;

class Media extends Model
{
    use EssentialTrait,HasFileManager;

    private const string MODEL_NAME = 'فایل';
    private const string MODEL_NAME_PLURAL = 'فایل‌ها';
    private const string GLOBAL_ICON = "rectangle-history";

    protected $fillable = [
        'original_name',
        'file_name',
        'extension',
        'mime_type',
        'size',
        'hash',
        'disk',
        'alt',
        'description',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Media $media) {
            if (is_null($media->uploaded_by) && auth()->check()) {
                $media->uploaded_by = auth()->id();
            }
        });

        // thumbnails first: each model removes its own row and file.
        static::deleting(function (Media $media) {
            $media->thumbnails->each->delete();
        });

        // main file last, only after the row is really gone.
        static::deleted(function (Media $media) {
            Storage::disk($media->disk)->delete($media->storagePath());
        });
    }

    // RELATIONS

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'uploaded_by');
    }

    public function thumbnails(): HasMany
    {
        return $this->hasMany(MediaThumbnail::class);
    }

    // GETTERS

    protected function fileFullName(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes) {
                $extension = $attributes['extension'] ?? null;

                return $extension
                    ? "{$attributes['file_name']}.{$extension}"
                    : $attributes['file_name'];
            },
        );
    }

    public function dateDirectoryFormat(): string
    {
        return $this->created_at->format('Y-m');
    }

    /**
     * path relative to the disk root, for use with Storage::disk($media->disk).
     */
    public function storagePath(): string
    {
        return $this->dateDirectoryFormat() . '/' . $this->file_full_name;
    }

    public function isThumbnailable(): bool
    {
        return in_array($this->mime_type, config('media.thumbnailable_mimes', []), true);
    }

    /**
     * Returns the thumbnail, generating it when needed.
     * Falls back to the media itself when no thumbnail is possible (unsupported type, no upscaling, errors).
     *
     * Without $height the lookup matches on width only (oldest first), so avoid requesting
     * the same width both with and without a height.
     */
    public function thumbnail(int $width, ?int $height = null): MediaThumbnail|Media
    {
        if (!$this->isThumbnailable()) {
            return $this;
        }

        $query = $this->thumbnails()->where('width', $width);
        if ($height !== null) {
            $query->where('height', $height);
        }
        $thumbnail = $query->orderBy('id')->first();

        try {
            $thumbnail ??= MediaFacade::generateThumbnail($this, $width, $height);

            if ($thumbnail === null) {
                return $this;
            }
            if (!$thumbnail->fileExists()) {
                MediaFacade::repairThumbnail($thumbnail);
            }

            return $thumbnail;
        } catch (Throwable $exception) {
            report($exception);

            return $this;
        }
    }

    // HANDLING CRUD

    public function handleUpdate(array $request)
    {
        return DB::transaction(function () use ($request) {
            if (isset($request['file_name']) && $this->file_name !== $request['file_name']) {
                // slugs the name, moves the file and saves the new file_name.
                MediaFacade::renameMedia($this, $request['file_name']);
            }
            // array_key_exists: lets the caller clear alt/description by sending null.
            if (array_key_exists('alt', $request)) $this->alt = $request['alt'];
            if (array_key_exists('description', $request)) $this->description = $request['description'];
            $this->saveOrFail();

            return $this;
        });
    }
}

<?php

namespace lhaamed\MediaModule\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use lhaamed\MediaModule\MediaFacade;
use lhaamed\MediaModule\MediaModels;
use lhaamed\MediaModule\Traits\HasFileManager;
use Throwable;

class Media extends Model
{
    use HasFileManager;


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

        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('media.created_at', 'desc');
        });

        static::creating(function (Media $media) {
            if (is_null($media->uploaded_by) && auth()->check()) {
                $media->uploaded_by = auth()->id();
            }
        });

        // thumbnails first: each model removes its own row and file.
        static::deleting(function (Media $media) {
            if ($media->isInUse()) {
                throw new Exception('این فایل در جایی استفاده شده و قابل حذف نیست.', 409);
            }

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
        return $this->hasMany(MediaModels::thumbnail());
    }

    public function mediaables(): HasMany
    {
        return $this->hasMany(Mediaable::class);
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


    public function usages(): Collection
    {
        $pivot = $this->mediaables()
            ->with('mediaable')
            ->get()
            ->map(fn ($row) => [
                'type'   => class_basename($row->mediaable_type),
                'id'     => $row->mediaable_id,
                'model'  => $row->mediaable,
                'source' => 'mediaables',
            ]);

        $direct = collect(config('media.usages', []))->flatMap(function ($target) {
            [$table, $column] = explode('.', $target);

            return DB::table($table)
                ->where($column, $this->getKey())
                ->pluck('id')
                ->map(fn ($id) => [
                    'type'   => $table,
                    'id'     => $id,
                    'model'  => null,
                    'source' => "$table.$column",
                ]);
        });

        return $pivot->concat($direct);
    }

    public function isInUse(): bool
    {
        return $this->usages()->isNotEmpty();
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


    /**
     * Placeholder for a file type, without needing a Media instance.
     * Order: extension, exact mime, mime group (video/*), default.
     *
     * @param string|array|null $extension  'pdf' or ['png', 'jpg'] (the first one is used)
     */
    public static function placeholderFor(string|array|null $extension = null, ?string $mime = null): string
    {
        $extension = strtolower(ltrim((string) (is_array($extension) ? reset($extension) : $extension), '.'));
        $placeholders = config('media.placeholders', []);

        $path = ($extension !== '' ? ($placeholders['extensions'][$extension] ?? null) : null)
            ?? ($mime ? ($placeholders['mimes'][$mime] ?? $placeholders['mimes'][Str::before($mime, '/') . '/*'] ?? null) : null)
            ?? config('media.placeholder');

        return asset($path);
    }

    public function placeholderUrl(): string
    {
        return self::placeholderFor($this->extension, $this->mime_type);
    }

    /**
     * عکس: thumbnail. بقیه‌ی فایل‌ها یا فایل گم‌شده: placeholder مخصوص نوعش.
     */
    public function previewUrl(int $width = 350, ?int $height = null): string
    {
        if ($this->isThumbnailable() && $this->fileExists()) {
            return $this->thumbnail($width,$height)->url();
        }

        return $this->placeholderUrl();
    }

    public static function uploadPlaceholder(): string
    {
        return asset(config('media.upload_placeholder'));
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

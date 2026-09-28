<?php

namespace lhaamed\MediaModule\Models;

use App\Models\User;
use lhaamed\MediaModule\MediaFacade;
use lhaamed\MediaModule\Traits\hasFileManager;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Media extends Model
{
    use hasFileManager;

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
        'uploaded_by'
    ];


    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        parent::boot();

        // thumbnails first: each model removes its own row and file.
        static::deleting(function (Media $media) {
            $media->thumbnails->each->delete();
        });

        static::creating(function (Media $media) {
            if (is_null($media->uploaded_by) && auth()->check()) {
                $media->uploaded_by = auth()->id();
            }
        });

        // main file last, only after the row is really gone.
        static::deleted(function (Media $media) {
            Storage::disk($media->disk)->delete($media->storagePath());
        });
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
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


    public function thumbnail($width, $height = null)
    {
        if ($height == null) {
            $thumbnail = $this->thumbnails()->where('width', $width)->first();
        } else {
            $thumbnail = $this->thumbnails()->where('width', $width)->where('height', $height)->first();
        }
        try {
            if (is_null($thumbnail)) {
                $thumbnail = MediaFacade::generateThumbnail($this, $width, $height);
            }
            if (is_null($thumbnail)) {
                return $this;
            } else {
                if (!$thumbnail->fileExists()) MediaFacade::repairThumbnail($thumbnail);
                return $thumbnail;
            }
        } catch (Throwable $exception) {
            return $this;
        }

    }


    // HANDLING CRUD

    public function handleUpdate(array $request)
    {
        return DB::transaction(function () use ($request) {
            if (isset($request['file_name']) && $this->file_name !== $request['file_name']) {
                if (MediaFacade::renameMedia($this, $request['file_name'])) {
                    $this->file_name = $request['file_name'];
                }
            }
            // array_key_exists: lets the caller clear alt/description by sending null.
            if (array_key_exists('alt', $request)) $this->alt = $request['alt'];
            if (array_key_exists('description', $request)) $this->description = $request['description'];
            $this->saveOrFail();
            foreach ($this->thumbnails as $thumbnail) {
                if (!$thumbnail->fileExists()) {
                    MediaFacade::repairThumbnail($thumbnail);
                }
            }
            return $this;
        });
    }
}

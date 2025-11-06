<?php

namespace lhaamed\MediaModule\Models;

use lhaamed\MediaModule\MediaFacade;
use lhaamed\MediaModule\Traits\hasFileManager;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class Media extends Model
{
    use hasFileManager;

    protected $fillable = [
        'key',
        'file_name',
        'mime_type',
        'disk',
        'alt',
        'description',
        'uploaded_by'
    ];

    protected static function boot(): void
    {
        parent::boot();

        self::deleting(function (Media $media) {
            if ($media->fileExists()) {
                unlink(public_path($media->pathToFile()));
                $media->thumbnails()->delete();
            }
        });

        self::creating(function (Media $media) {
            if (auth()->check()) {
                $media->uploaded_by = auth()->id();
            }
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

    protected function fileName(): Attribute
    {
        return Attribute::make(
            get: fn(string $value, array $attributes) => $value,
        );
    }

    protected function mimeType(): Attribute
    {
        return Attribute::make(
            get: fn(string $value, array $attributes) => $value,
        );
    }

    protected function fileFullName(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $attributes['file_name'] . '.' . $attributes['mime_type'],
        );
    }

    protected function disk(): Attribute
    {
        return Attribute::make(
            get: fn(string $value, array $attributes) => $value,
        );
    }

    protected function alt(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $value,
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value, array $attributes) => $value,
        );
    }

    public function dateDirectoryFormat(): string
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $this->created_at)->format('Y-m');
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
//        $request = Help::selectFromArray($request, ['file_name', 'alt', 'description']);
        return DB::transaction(function () use ($request) {
            if (isset($request['file_name']) && $this->file_name !== $request['file_name']) {
                if (MediaFacade::renameMedia($this, $request['file_name'])) {
                    $this->file_name = $request['file_name'];
                }
            }
            if (isset($request['alt'])) $this->alt = $request['alt'];
            if (isset($request['description'])) $this->description = $request['description'];
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

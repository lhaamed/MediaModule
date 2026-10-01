<?php

namespace lhaamed\MediaModule\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use lhaamed\MediaModule\MediaModels;
use lhaamed\MediaModule\Models\Media;
use lhaamed\MediaModule\Models\Mediaable;

trait HasMedia
{
    public static function bootHasMedia(): void
    {
        static::deleted(function ($model) {
            // soft delete: keep attachments
            if (method_exists($model, 'isForceDeleting') && !$model->isForceDeleting()) {
                return;
            }
            $model->media()->detach();
        });
    }

    public function media(): MorphToMany
    {
        return $this->morphToMany(MediaModels::media(), 'mediaable')
            ->using(Mediaable::class)
            ->withPivot('collection', 'order')
            ->orderByPivot('order');
    }

    public function mediaIn(string $collection = 'default'): MorphToMany
    {
        return $this->media()->wherePivot('collection', $collection);
    }

    public function attachMedia(Media|int $media, string $collection = 'default', int $order = 0): void
    {
        $id = $media instanceof Media ? $media->id : $media;

        if ($this->mediaIn($collection)->wherePivot('media_id', $id)->exists()) {
            return;
        }

        $this->media()->attach($id, ['collection' => $collection, 'order' => $order]);
    }

    public function detachMedia(Media|int $media, ?string $collection = null): void
    {
        $id = $media instanceof Media ? $media->id : $media;

        $query = $this->media()->wherePivot('media_id', $id);
        if ($collection !== null) {
            $query->wherePivot('collection', $collection);
        }
        $query->detach();
    }

    public function thumbnailUrl(string $collection, int $width = 350, ?int $height = null): string
    {
        $media = $this->mediaIn($collection)->first();

        return $media
            ? $media->previewUrl($width,$height)
            : MediaModels::media()::placeholderFor($media?->exnteition ?: 'file');
    }
}

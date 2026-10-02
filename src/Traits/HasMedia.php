<?php

namespace lhaamed\MediaModule\Traits;

use Illuminate\Database\Eloquent\Model;
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

    public function mediaAt(string $collection, int $order): ?Model
    {
        return $this->mediaIn($collection)
            ->wherePivot('order', $order)
            ->orderBy('media.id')
            ->first()
            ?? $this->mediaIn($collection)->first();
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

    public function detachAllMedia(string $collection): void
    {
        $this->media()->wherePivot('collection', $collection)->detach();
    }

    public function previewUrl(string $collection, ?int $width = null, ?int $height = null, int $order = 0): string
    {
        $media = $this->mediaIn($collection)->first();

        return $media
            ? $media->previewUrl($width, $height)
            : MediaModels::media()::placeholderFor($media?->exnteition ?: 'general');
    }
}

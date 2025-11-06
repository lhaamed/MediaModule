<?php


namespace lhaamed\MediaModule\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use lhaamed\MediaModule\Models\Media;

trait hasMedia
{

    // RELATIONS
    public function media(): MorphToMany
    {
        return $this->morphToMany(Media::class, 'mediaable');
    }

}

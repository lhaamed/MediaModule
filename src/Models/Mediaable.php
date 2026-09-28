<?php

namespace lhaamed\MediaModule\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Mediaable extends MorphPivot
{
    protected $table = 'mediaables';

    public $incrementing = true;

    public $timestamps = false;

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function mediaable(): MorphTo
    {
        return $this->morphTo();
    }
}

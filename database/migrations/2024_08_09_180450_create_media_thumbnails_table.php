<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_thumbnails')) {
            return;
        }

        Schema::create('media_thumbnails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('file_name');                       // path relative to the media disk
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');            // actual generated size
            $table->unsignedBigInteger('size')->default(0);    // bytes
            $table->timestamps();

            $table->unique(['media_id', 'width', 'height']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_thumbnails');
    }
};

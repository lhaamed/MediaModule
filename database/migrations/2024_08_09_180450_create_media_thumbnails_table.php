<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_thumbnails', function (Blueprint $table) {
            $table->id();
            $table->string('file_name'); // stored thumbnail path relative to media disk
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes
            $table->timestamps();

            $table->unique(['media_id', 'width', 'height']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_thumbnails');
    }
};

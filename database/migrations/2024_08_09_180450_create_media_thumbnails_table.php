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
            $table->increments('id');
            $table->unsignedInteger('media_id')->nullable();
            $table->smallInteger('width');
            $table->smallInteger('height')->nullable();


            // if the media deletes. the thumbnail will delete immediately.
            $table->foreign('media_id')
                ->references('id')
                ->on('media')
                ->onDelete('cascade')
                ->onUpdate('cascade');
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

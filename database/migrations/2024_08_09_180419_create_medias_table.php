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
        Schema::create('media', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->nullable();
            $table->string('file_name')->unique()->index();
            $table->string('mime_type',6);
            $table->string('disk',10)->default('media');
            $table->string('alt',255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('mediaables', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('media_id');
            $table->morphs('mediaable');

            //FOREIGN KEY CONSTRAINTS
            $table->foreign('media_id')
                ->references('id')
                ->on('media')
                ->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mediaables');
        Schema::dropIfExists('media');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installs (v1 schema) are converted by the upgrade migration.
        if (Schema::hasTable('media')) {
            return;
        }

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');                      // slug of the original name, no extension
            $table->string('original_name')->index();         // exactly what the client sent
            $table->string('extension', 20)->nullable();
            $table->string('mime_type', 127)->index();        // detected from file content
            $table->string('disk', 50);
            $table->unsignedBigInteger('size')->default(0);   // bytes
            $table->char('hash', 64)->nullable()->index();    // sha256
            $table->string('alt')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['disk', 'file_name']);
        });

        Schema::create('mediaables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->morphs('mediaable');
            $table->string('collection', 100)->default('default');
            $table->unsignedInteger('order')->default(0);

            $table->unique(
                ['media_id', 'mediaable_type', 'mediaable_id', 'collection'],
                'mediaables_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediaables');
        Schema::dropIfExists('media');
    }
};

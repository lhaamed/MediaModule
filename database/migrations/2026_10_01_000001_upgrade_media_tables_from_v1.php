<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mime\MimeTypes;

/**
 * Converts the v1 schema in place:
 *  - media.mime_type held the extension          => extension + a MIME type guessed from it
 *  - media.key held the role of the attachment   => mediaables.collection
 *  - thumbnails had no path/size/timestamps      => file_name (legacy path), size, timestamps
 *
 * Skipped on fresh installs. Back up the database first: DDL is not transactional
 * and down() is not supported. Run `php artisan media:upgrade` afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `key` is dropped last, so its presence means "v1 schema, not upgraded yet".
        if (!Schema::hasTable('media') || !Schema::hasColumn('media', 'key')) {
            return;
        }

        $this->upgradeMedia();
        $this->convertMediaData();
        $this->finishMedia();
        $this->upgradeMediaables();
        $this->upgradeThumbnails();

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('This upgrade cannot be reversed. Restore your database backup.');
    }

    private function upgradeMedia(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('file_name');
            $table->string('extension', 20)->nullable()->after('original_name');
            $table->unsignedBigInteger('size')->default(0)->after('mime_type');
            $table->char('hash', 64)->nullable()->after('size');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->string('mime_type', 127)->change();
            $table->string('disk', 50)->default('media')->change();
        });
    }

    private function convertMediaData(): void
    {
        // v1 stored the extension in mime_type.
        DB::table('media')->update(['extension' => DB::raw('LOWER(mime_type)')]);

        // one UPDATE per distinct extension, no file access. `media:upgrade` refines it from the real files.
        $mimes = MimeTypes::getDefault();
        DB::table('media')->distinct()->pluck('extension')->each(function ($extension) use ($mimes) {
            $mime = $mimes->getMimeTypes((string) $extension)[0] ?? 'application/octet-stream';
            DB::table('media')->where('extension', $extension)->update(['mime_type' => $mime]);
        });

        $full = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT(file_name, '.', extension)"
            : "file_name || '.' || extension";

        DB::statement(
            "UPDATE media SET original_name = CASE WHEN extension IS NULL OR extension = '' THEN file_name ELSE {$full} END"
        );
    }

    private function finishMedia(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('original_name')->change();

            // v1 had a global unique on file_name; uniqueness is now per disk.
            $table->dropUnique(['file_name']);
            $table->dropIndex(['file_name']);
            $table->unique(['disk', 'file_name']);

            $table->index('original_name');
            $table->index('mime_type');
            $table->index('hash');
            $table->index('uploaded_by');
        });
    }

    private function upgradeMediaables(): void
    {
        Schema::table('mediaables', function (Blueprint $table) {
            $table->string('collection', 100)->default('default')->after('mediaable_id');
            $table->unsignedInteger('order')->default(0)->after('collection');
        });

        // the role that lived on media.key now belongs to the attachment.
        $key = DB::getQueryGrammar()->wrap('key');
        DB::statement(
            "UPDATE mediaables SET collection = COALESCE(NULLIF(SUBSTR((SELECT m.{$key} FROM media m WHERE m.id = mediaables.media_id), 1, 100), ''), 'default')"
        );

        // v1 had no unique constraint: drop duplicate attachments before adding it.
        DB::statement(
            'DELETE FROM mediaables WHERE id NOT IN (SELECT keep_id FROM (SELECT MIN(id) AS keep_id FROM mediaables GROUP BY media_id, mediaable_type, mediaable_id, collection) AS keepers)'
        );

        Schema::table('mediaables', function (Blueprint $table) {
            $table->unique(['media_id', 'mediaable_type', 'mediaable_id', 'collection'], 'mediaables_unique');
        });
    }

    private function upgradeThumbnails(): void
    {
        // thumbnails are a cache (regenerated on demand): drop rows that cannot be identified or reproduced.
        DB::table('media_thumbnails')->whereNull('media_id')->orWhereNull('height')->delete();

        DB::statement(
            'DELETE FROM media_thumbnails WHERE id NOT IN (SELECT keep_id FROM (SELECT MIN(id) AS keep_id FROM media_thumbnails GROUP BY media_id, width, height) AS keepers)'
        );

        Schema::table('media_thumbnails', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('media_id');
            $table->unsignedBigInteger('size')->default(0)->after('height');
            $table->timestamps();
        });

        // v1 built the path from the media: {Y-m of created_at}/thumbnails/{file_name}-{width}-{height}.{extension}
        DB::table('media_thumbnails as t')
            ->join('media as m', 'm.id', '=', 't.media_id')
            ->select('t.id', 't.width', 't.height', 'm.file_name as media_file_name', 'm.extension', 'm.created_at')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $directory = Carbon::parse($row->created_at)->format('Y-m');

                    DB::table('media_thumbnails')->where('id', $row->id)->update([
                        'file_name' => "{$directory}/thumbnails/{$row->media_file_name}-{$row->width}-{$row->height}.{$row->extension}",
                    ]);
                }
            }, 't.id', 'id');

        Schema::table('media_thumbnails', function (Blueprint $table) {
            $table->string('file_name')->change();
            $table->unsignedInteger('media_id')->change();
            $table->unsignedSmallInteger('width')->change();
            $table->unsignedSmallInteger('height')->change();

            $table->unique(['media_id', 'width', 'height']);
        });
    }
};

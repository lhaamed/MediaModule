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
 *  - legacy table photo_thumbnails               => media_thumbnails
 *
 * Skipped on fresh installs. MySQL DDL is not transactional, so every step is safe to run
 * again: a failed run can simply be repeated. Back up the database first: down() is not supported.
 * Run `php artisan media:upgrade` afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `key` is dropped last, so its presence means "v1 schema, not fully upgraded yet".
        if (!Schema::hasTable('media') || !Schema::hasColumn('media', 'key')) {
            return;
        }

        $this->adoptLegacyThumbnailsTable();
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

    private function adoptLegacyThumbnailsTable(): void
    {
        if (!Schema::hasTable('photo_thumbnails')) {
            return;
        }

        if (Schema::hasTable('media_thumbnails')) {
            if (DB::table('media_thumbnails')->exists()) {
                throw new RuntimeException('Both photo_thumbnails and media_thumbnails exist and media_thumbnails has rows. Resolve this manually.');
            }

            // an empty table created by the package's create migration
            Schema::drop('media_thumbnails');
        }

        Schema::rename('photo_thumbnails', 'media_thumbnails');
    }

    private function upgradeMedia(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'original_name')) {
                $table->string('original_name')->nullable()->after('file_name');
            }
            if (!Schema::hasColumn('media', 'extension')) {
                $table->string('extension', 20)->nullable()->after('original_name');
            }
            if (!Schema::hasColumn('media', 'size')) {
                $table->unsignedBigInteger('size')->default(0)->after('mime_type');
            }
            if (!Schema::hasColumn('media', 'hash')) {
                $table->char('hash', 64)->nullable()->after('size');
            }
        });

        Schema::table('media', function (Blueprint $table) {
            $table->string('mime_type', 127)->change();
            $table->string('disk', 50)->default('media')->change();
        });
    }

    private function convertMediaData(): void
    {
        // legacy rows only: v1 stored the extension in mime_type, a real MIME type always contains "/".
        $legacy = fn () => DB::table('media')->where('mime_type', 'not like', '%/%');

        $legacy()->update(['extension' => DB::raw('LOWER(mime_type)')]);

        // one UPDATE per distinct extension, no file access. `media:upgrade` refines it from the real files.
        $mimes = MimeTypes::getDefault();
        $legacy()->distinct()->pluck('extension')->each(function ($extension) use ($mimes, $legacy) {
            $mime = $mimes->getMimeTypes((string) $extension)[0] ?? 'application/octet-stream';
            $legacy()->where('extension', $extension)->update(['mime_type' => $mime]);
        });

        $full = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT(file_name, '.', extension)"
            : "file_name || '.' || extension";

        DB::statement(
            "UPDATE media SET original_name = CASE WHEN extension IS NULL OR extension = '' THEN file_name ELSE {$full} END WHERE original_name IS NULL"
        );
    }

    private function finishMedia(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('original_name')->change();
        });

        // v1 had a global unique on file_name; uniqueness is now per disk.
        $this->dropSingleColumnIndexes('media', 'file_name');
        $this->ensureIndex('media', ['disk', 'file_name'], unique: true);

        $this->ensureIndex('media', ['original_name']);
        $this->ensureIndex('media', ['mime_type']);
        $this->ensureIndex('media', ['hash']);
        $this->ensureIndex('media', ['uploaded_by']);
    }

    private function upgradeMediaables(): void
    {
        Schema::table('mediaables', function (Blueprint $table) {
            if (!Schema::hasColumn('mediaables', 'collection')) {
                $table->string('collection', 100)->default('default')->after('mediaable_id');
            }
            if (!Schema::hasColumn('mediaables', 'order')) {
                $table->unsignedInteger('order')->default(0)->after('collection');
            }
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

        $this->ensureIndex(
            'mediaables',
            ['media_id', 'mediaable_type', 'mediaable_id', 'collection'],
            unique: true,
            name: 'mediaables_unique'
        );
    }

    private function upgradeThumbnails(): void
    {
        // thumbnails are a cache (regenerated on demand): drop rows that cannot be identified or reproduced.
        DB::table('media_thumbnails')->whereNull('media_id')->orWhereNull('height')->delete();

        DB::statement(
            'DELETE FROM media_thumbnails WHERE id NOT IN (SELECT keep_id FROM (SELECT MIN(id) AS keep_id FROM media_thumbnails GROUP BY media_id, width, height) AS keepers)'
        );

        Schema::table('media_thumbnails', function (Blueprint $table) {
            if (!Schema::hasColumn('media_thumbnails', 'file_name')) {
                $table->string('file_name')->nullable()->after('media_id');
            }
            if (!Schema::hasColumn('media_thumbnails', 'size')) {
                $table->unsignedBigInteger('size')->default(0)->after('height');
            }
            if (!Schema::hasColumn('media_thumbnails', 'created_at')) {
                $table->timestamps();
            }
        });

        // v1 built the path from the media: {Y-m of created_at}/thumbnails/{file_name}-{width}-{height}.{extension}
        DB::table('media_thumbnails as t')
            ->join('media as m', 'm.id', '=', 't.media_id')
            ->whereNull('t.file_name')
            ->select('t.id', 't.width', 't.height', 'm.file_name as media_file_name', 'm.extension', 'm.created_at')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $directory = Carbon::parse($row->created_at)->format('Y-m');

                    DB::table('media_thumbnails')->where('id', $row->id)->update([
                        'file_name' => "{$directory}/thumbnails/{$row->media_file_name}-{$row->width}-{$row->height}.{$row->extension}",
                    ]);
                }
            }, 't.id', 'id');

        // media_id must have exactly the type of media.id, or the foreign key breaks.
        $mediaIdType = $this->mediaIdIsBigInt() ? 'unsignedBigInteger' : 'unsignedInteger';

        Schema::table('media_thumbnails', function (Blueprint $table) use ($mediaIdType) {
            $table->string('file_name')->change();
            $table->{$mediaIdType}('media_id')->change();
            $table->unsignedSmallInteger('width')->change();
            $table->unsignedSmallInteger('height')->change();
        });

        $this->ensureIndex('media_thumbnails', ['media_id', 'width', 'height'], unique: true);
    }

    // HELPERS

    /**
     * Laravel 11+ has native schema introspection. Laravel 10 falls back to MySQL's SHOW INDEX.
     */
    private function indexesOf(string $table): array
    {
        if (method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
            return Schema::getIndexes($table);
        }

        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->groupBy('Key_name')
            ->map(fn ($rows, $name) => [
                'name' => $name,
                'columns' => $rows->sortBy('Seq_in_index')->pluck('Column_name')->all(),
                'unique' => !$rows->first()->Non_unique,
                'primary' => $name === 'PRIMARY',
            ])
            ->values()
            ->all();
    }

    private function ensureIndex(string $table, array $columns, bool $unique = false, ?string $name = null): void
    {
        foreach ($this->indexesOf($table) as $index) {
            if ($index['columns'] === $columns && (bool) $index['unique'] === $unique) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $unique, $name) {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }

    private function dropSingleColumnIndexes(string $table, string $column): void
    {
        foreach ($this->indexesOf($table) as $index) {
            if ($index['columns'] === [$column] && !$index['primary']) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']);
                });
            }
        }
    }

    private function mediaIdIsBigInt(): bool
    {
        if (method_exists(Schema::getFacadeRoot(), 'getColumns')) {
            $column = collect(Schema::getColumns('media'))->firstWhere('name', 'id');

            return str_contains(strtolower($column['type'] ?? ''), 'bigint');
        }

        return str_contains(strtolower(DB::selectOne("SHOW COLUMNS FROM `media` WHERE Field = 'id'")->Type), 'bigint');
    }
};

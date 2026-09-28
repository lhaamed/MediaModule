<?php

namespace lhaamed\MediaModule\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Second half of the v1 upgrade (the first half is the upgrade migration).
 * Reads the real files to fill mime_type (from content), size and hash, and reports suspicious rows.
 */
class UpgradeMediaCommand extends Command
{
    protected $signature = 'media:upgrade
        {--dry-run : Report what would change without writing}
        {--force : Reprocess rows that already have a hash}
        {--chunk=200 : Rows per batch}';

    protected $description = 'Fill mime_type (from file content), size and hash for media migrated from the v1 schema.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $blocked = config('media.blocked_extensions', []);
        $mimes = MimeTypes::getDefault();

        $stats = [
            'checked' => 0,
            'updated' => 0,
            'file missing' => 0,
            'mime type changed' => 0,
            'extension does not match content' => 0,
            'blocked extension' => 0,
            'failed' => 0,
        ];
        $notes = [
            'file missing' => [],
            'extension does not match content' => [],
            'blocked extension' => [],
            'failed' => [],
        ];

        $query = DB::table('media');
        if (!$this->option('force')) {
            $query->whereNull('hash');
        }

        $query->chunkById($chunk, function ($rows) use (&$stats, &$notes, $dryRun, $blocked, $mimes) {
            foreach ($rows as $row) {
                $stats['checked']++;

                try {
                    $extension = (string) $row->extension;
                    $path = Carbon::parse($row->created_at)->format('Y-m') . '/' . $row->file_name
                        . ($extension !== '' ? '.' . $extension : '');
                    $disk = Storage::disk($row->disk);

                    if (!$disk->exists($path)) {
                        $stats['file missing']++;
                        $notes['file missing'][] = "#{$row->id} {$row->disk}:{$path}";
                        continue;
                    }

                    $mime = $disk->mimeType($path) ?: $row->mime_type;

                    if ($mime !== $row->mime_type) {
                        $stats['mime type changed']++;
                    }

                    $valid = $mimes->getExtensions($mime);
                    if ($valid && !in_array($extension, $valid, true)) {
                        $stats['extension does not match content']++;
                        $notes['extension does not match content'][] = "#{$row->id} {$path} is {$mime}";
                    }
                    if (in_array($extension, $blocked, true)) {
                        $stats['blocked extension']++;
                        $notes['blocked extension'][] = "#{$row->id} {$path}";
                    }

                    if (!$dryRun) {
                        DB::table('media')->where('id', $row->id)->update([
                            'mime_type' => $mime,
                            'size' => $disk->size($path),
                            'hash' => $this->hashOf($disk, $path),
                        ]);
                    }
                    $stats['updated']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $notes['failed'][] = "#{$row->id}: {$e->getMessage()}";
                }
            }
        });

        $thumbnails = $this->fillThumbnailSizes($chunk, $dryRun);

        $this->table(['media', 'count'], collect($stats)->map(fn ($count, $label) => [$label, $count])->values()->all());
        $this->line("thumbnail sizes filled: {$thumbnails}");

        foreach ($notes as $label => $items) {
            if ($items) {
                $this->warn(ucfirst($label) . ' (' . count($items) . ', showing up to 20):');
                foreach (array_slice($items, 0, 20) as $item) {
                    $this->line("  {$item}");
                }
            }
        }

        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function fillThumbnailSizes(int $chunk, bool $dryRun): int
    {
        $filled = 0;

        DB::table('media_thumbnails as t')
            ->join('media as m', 'm.id', '=', 't.media_id')
            ->where('t.size', 0)
            ->select('t.id', 't.file_name', 'm.disk')
            ->chunkById($chunk, function ($rows) use (&$filled, $dryRun) {
                foreach ($rows as $row) {
                    try {
                        $disk = Storage::disk($row->disk);

                        // missing files are rebuilt on demand by Media::thumbnail().
                        if (!$disk->exists($row->file_name)) {
                            continue;
                        }
                        if (!$dryRun) {
                            DB::table('media_thumbnails')->where('id', $row->id)->update(['size' => $disk->size($row->file_name)]);
                        }
                        $filled++;
                    } catch (Throwable) {
                        // leave the row as is
                    }
                }
            }, 't.id', 'id');

        return $filled;
    }

    /**
     * sha256 of the file, streamed so large files do not load into memory.
     */
    private function hashOf($disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (!is_resource($stream)) {
            throw new \RuntimeException('could not open the file for reading.');
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}

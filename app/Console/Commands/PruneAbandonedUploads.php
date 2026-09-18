<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostMedia;
use App\Services\Posts\VideoUploadService;
use App\Services\Publishing\InstagramReelCover;
use App\Services\Publishing\YouTubeThumbnail;
use App\Support\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PruneAbandonedUploads extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Delete abandoned presigned-upload tmp files under tmp/media/ older than 6 hours.';

    public function handle(): int
    {
        app(VideoUploadService::class)->pruneExpired();
        $disk = FileStorage::disk();
        $cutoff = Carbon::now()->subHours(6)->getTimestamp();
        $deleted = 0;

        // allFiles()/listContents() does not honor the disk's `throw => false`
        // flag, so a transient S3 listing error (throttling, timeout, creds)
        // throws here and would fail the whole scheduled run. Keep it best-effort:
        // log and fall through to the DB-orphan prune, which retries next hour.
        try {
            foreach ($disk->allFiles('tmp/media') as $file) {
                // lastModified() DOES honor `throw => false` and returns false
                // (coerced to 0) on a per-object error; require a positive
                // timestamp so we never treat an unreadable mtime as "ancient"
                // and delete a file we could not actually inspect.
                $lastModified = $disk->lastModified($file);

                if ($lastModified > 0 && $lastModified < $cutoff) {
                    $disk->delete($file);
                    $deleted++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Skipping tmp/media prune after a storage error: '.$e->getMessage());
        }

        if ($deleted > 0) {
            Log::info("Pruned {$deleted} abandoned upload file(s).");
        }

        $this->info("Pruned {$deleted} abandoned upload file(s).");

        // A DM attachment has no post but is not abandoned: its bubble keeps
        // rendering the file after the message is sent.
        $orphans = PostMedia::query()->withoutGlobalScopes()
            ->whereNull('post_id')
            ->whereNull('direct_message_id')
            ->where('created_at', '<', Carbon::now()->subHours(6))
            ->get();

        $prunedRecords = 0;
        foreach ($orphans as $orphan) {
            if (app(InstagramReelCover::class)->isReferenced($orphan) || app(YouTubeThumbnail::class)->isReferenced($orphan)) {
                continue;
            }
            $orphan->delete(); // model's deleting hook removes the underlying file(s)
            $prunedRecords++;
        }

        if ($prunedRecords > 0) {
            Log::info('Pruned '.$prunedRecords.' orphan media record(s).');
        }

        return self::SUCCESS;
    }
}

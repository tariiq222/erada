<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgePrivateAttachmentsCommand extends Command
{
    protected $signature = 'attachments:purge-private
        {--days=30 : Retention days before purging soft-deleted private comment attachments}
        {--dry-run : Report eligible rows without deleting files or rows}';

    protected $description = 'Purge eligible soft-deleted private comment attachment files after retention.';

    public function handle(): int
    {
        $days = $this->option('days');

        if (! is_numeric($days) || (string) (int) $days !== (string) $days || (int) $days < 0) {
            $this->error('The --days option must be a non-negative integer.');

            return self::FAILURE;
        }

        $days = (int) $days;
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $summary = [
            'eligible_count' => 0,
            'purged_count' => 0,
            'missing_file_count' => 0,
            'attachment_ids' => [],
            'missing_file_attachment_ids' => [],
            'dry_run' => $dryRun,
            'retention_days' => $days,
        ];

        Attachment::onlyTrashed()
            ->where('attachable_type', Comment::class)
            ->where('deleted_at', '<=', $cutoff)
            ->where('file_path', 'like', 'comments/%')
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use (&$summary, $dryRun): void {
                foreach ($attachments as $attachment) {
                    $summary['eligible_count']++;
                    $summary['attachment_ids'][] = $attachment->id;

                    $fileExists = Storage::disk('local')->exists($attachment->file_path);

                    if (! $fileExists) {
                        $summary['missing_file_count']++;
                        $summary['missing_file_attachment_ids'][] = $attachment->id;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    if ($fileExists) {
                        Storage::disk('local')->delete($attachment->file_path);
                    }

                    $attachment->forceDelete();
                    $summary['purged_count']++;
                }
            });

        $this->logPurgeSummary($summary);

        $this->info(sprintf(
            'Eligible: %d; purged: %d; missing files: %d; dry-run: %s.',
            $summary['eligible_count'],
            $summary['purged_count'],
            $summary['missing_file_count'],
            $dryRun ? 'yes' : 'no'
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{eligible_count:int,purged_count:int,missing_file_count:int,attachment_ids:array<int,int>,missing_file_attachment_ids:array<int,int>,dry_run:bool,retention_days:int}  $summary
     */
    private function logPurgeSummary(array $summary): void
    {
        ActivityLog::create([
            'user_id' => null,
            'action' => 'private_attachments_purged',
            'description' => 'Private comment attachment retention purge summary.',
            'loggable_type' => Attachment::class,
            'loggable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'metadata' => $summary,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}

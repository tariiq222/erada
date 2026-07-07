<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ActivityLogOrganizationResolver;
use Illuminate\Console\Command;

/**
 * php artisan activity-logs:backfill-organization
 *
 *   --dry-run     لا يكتب، يطبع تقريراً فقط.
 *   --chunk=500   حجم الـ chunk لـ chunkById.
 *   --only-null   (افتراضي) يحدّث الصفوف التي organization_id = null فقط.
 *                 --only-null=false يعيد الاشتقاق لكل صف (force overwrite).
 *
 * يمر على activity_logs بمحاولة استخراج organization_id من loggable/scope_/user.
 * يستعمل ActivityLogOrganizationResolver نفسه الذي يستخدمه creating observer،
 * فيبقى السلوك موحّداً بين الإنتاج والـ backfill.
 */
class ActivityLogsBackfillOrganizationCommand extends Command
{
    protected $signature = 'activity-logs:backfill-organization
                            {--dry-run : لا يكتب، يطبع تقريراً فقط}
                            {--chunk=500 : حجم الـ chunk}
                            {--only-null : (افتراضي) يحدّث فقط الصفوف ذات organization_id = null}';

    protected $description = 'تعبئة organization_id للسجلات القديمة عبر الـ Resolver';

    public function handle(ActivityLogOrganizationResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $onlyNull = $this->option('only-null') !== false; // default true

        $total = 0;
        $updated = 0;
        $skipped = 0;
        $unresolved = 0;
        $errors = 0;
        $sourceCounts = [
            'loggable' => 0,
            'scope' => 0,
            'target_user' => 0,
            'actor' => 0,
            'none' => 0,
        ];
        $samples = [];

        $this->info($dryRun ? '--- DRY-RUN (لن تُكتب تغييرات) ---' : '--- تنفيذ ---');

        // عطل الـ observer داخل الـ saveQuietly() كي لا يطلق re-fill متكرراً.
        $previousFlag = ActivityLog::$fillOrganization;
        ActivityLog::$fillOrganization = false;

        try {
            $query = ActivityLog::query();
            if ($onlyNull) {
                $query->whereNull('organization_id');
            }

            $query->orderBy('id')->chunkById($chunk, function ($logs) use (
                $resolver, $dryRun, $onlyNull, &$total, &$updated, &$skipped, &$unresolved, &$errors, &$sourceCounts, &$samples
            ) {
                foreach ($logs as $log) {
                    $total++;

                    $payload = $log->getAttributes();
                    $trace = $resolver->resolveWithTrace($payload);
                    $source = $trace['source'] ?? 'none';
                    $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;

                    if ($source === 'none' || $trace['organization_id'] === null) {
                        $unresolved++;
                        if (count($samples) < 20) {
                            $samples[] = [
                                'id' => $log->id,
                                'action' => $log->action,
                                'loggable_type' => $log->loggable_type,
                                'loggable_id' => $log->loggable_id,
                                'user_id' => $log->user_id,
                                'target_user_id' => $log->target_user_id,
                                'scope_type' => $log->scope_type,
                                'scope_id' => $log->scope_id,
                                'reason' => $trace['reason'] ?? null,
                            ];
                        }
                        continue;
                    }

                    if (! $onlyNull && $log->organization_id !== null
                        && (int) $log->organization_id === (int) $trace['organization_id']) {
                        $skipped++;
                        continue;
                    }

                    if (! $dryRun) {
                        try {
                            $log->organization_id = $trace['organization_id'];
                            $log->saveQuietly();
                            $updated++;
                        } catch (\Throwable $e) {
                            $errors++;
                            $this->warn("Failed to update log #{$log->id}: {$e->getMessage()}");
                        }
                    } else {
                        $updated++;
                    }
                }
            });
        } finally {
            ActivityLog::$fillOrganization = $previousFlag;
        }

        $this->newLine();
        $this->info('==== activity-logs:backfill-organization report ====');
        $this->table(
            ['total', 'updated', 'skipped', 'unresolved', 'errors'],
            [[$total, $updated, $skipped, $unresolved, $errors]]
        );

        $this->info('source_breakdown:');
        $this->table(
            ['loggable', 'scope', 'target_user', 'actor', 'none'],
            [[
                $sourceCounts['loggable'],
                $sourceCounts['scope'],
                $sourceCounts['target_user'],
                $sourceCounts['actor'],
                $sourceCounts['none'],
            ]]
        );

        if (! empty($samples)) {
            $this->warn('first unresolved samples ('.($errors === 0 ? 'review needed' : 'errors above').'):');
            $this->table(
                ['id', 'action', 'loggable_type', 'loggable_id', 'user_id', 'reason'],
                array_map(fn ($s) => [
                    $s['id'], $s['action'], $s['loggable_type'], $s['loggable_id'],
                    $s['user_id'], $s['reason'],
                ], $samples)
            );
        }

        if ($errors > 0) {
            $this->error("{$errors} row(s) failed to update.");
            return self::FAILURE;
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
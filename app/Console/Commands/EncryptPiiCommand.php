<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Console\Command;

class EncryptPiiCommand extends Command
{
    protected $signature = 'pii:encrypt
        {--chunk=200 : Chunk size for batch processing}
        {--dry-run : Count rows that would be re-saved without writing}';

    protected $description = 'Re-save PII columns so Laravel\'s encrypted cast encrypts any existing plaintext values. Idempotent and safe to run multiple times.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dry = (bool) $this->option('dry-run');

        $targets = [
            'users' => [
                User::query(),
                ['phone', 'job_title'],
            ],
            'ovr_incident_reports' => [
                IncidentReport::query(),
                ['patient_name', 'patient_file_number'],
            ],
            'survey_responses' => [
                SurveyResponse::query(),
                ['respondent_name'],
            ],
        ];

        foreach ($targets as $label => [$query, $columns]) {
            $query->where(function ($q) use ($columns) {
                foreach ($columns as $col) {
                    $q->orWhereNotNull($col);
                }
            });

            $total = (clone $query)->count();
            $this->info(sprintf('[%s] %d row(s) match PII columns.', $label, $total));

            if ($dry || $total === 0) {
                continue;
            }

            $updated = 0;
            $query->orderBy($query->getModel()->getKeyName())
                ->chunkById($chunk, function ($rows) use (&$updated) {
                    foreach ($rows as $row) {
                        $row->save();
                        $updated++;
                    }
                });

            $this->info(sprintf('[%s] Re-saved %d row(s); PII columns re-encrypted via cast.', $label, $updated));
        }

        return self::SUCCESS;
    }
}

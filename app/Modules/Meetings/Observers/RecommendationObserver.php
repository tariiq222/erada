<?php

namespace App\Modules\Meetings\Observers;

use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\ReferenceNumberGenerator;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class RecommendationObserver
{
    public function creating(Recommendation $recommendation): void
    {
        if (empty($recommendation->reference_number)) {
            $year = substr((string) now(), 0, 4);
            $recommendation->reference_number = app(ReferenceNumberGenerator::class)->generate('REC', $year);
        }
    }

    public function created(Recommendation $r): void
    {
        $this->log($r, 'created', null, [
            'title' => $r->title,
            'priority' => $r->priority,
            'status' => $r->status,
        ]);
    }

    public function updated(Recommendation $r): void
    {
        $original = $r->getOriginal();

        if ($r->wasChanged('status')) {
            $this->log($r, 'status_changed', ['status' => $original['status'] ?? null], ['status' => $r->status]);

            return;
        }

        $changes = $r->getChanges();
        unset($changes['updated_at']);
        if (empty($changes)) {
            return;
        }

        $old = [];
        $new = [];
        foreach ($changes as $f => $v) {
            $old[$f] = $original[$f] ?? null;
            $new[$f] = $v;
        }
        $this->log($r, 'updated', $old, $new);
    }

    public function deleted(Recommendation $r): void
    {
        $this->log($r, 'deleted', ['title' => $r->title], null);
    }

    protected function log(Recommendation $r, string $action, ?array $old, ?array $new): void
    {
        ActivityLog::create([
            'user_id' => Auth::id() ?? auth('sanctum')->id() ?? request()->user()?->id,
            'action' => $action,
            'loggable_type' => Recommendation::class,
            'loggable_id' => $r->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}

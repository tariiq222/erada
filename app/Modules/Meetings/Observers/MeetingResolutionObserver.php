<?php

namespace App\Modules\Meetings\Observers;

use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Support\ReferenceNumberGenerator;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * MeetingResolutionObserver — Phase 1 / Direction R.
 *
 * Mirrors RecommendationObserver: auto-generates the human-readable
 * reference_number on `creating`, then writes ActivityLog entries on
 * created / updated / deleted so per-resolution audit rows show up in
 * the global admin feed.
 */
class MeetingResolutionObserver
{
    public function creating(MeetingResolution $resolution): void
    {
        if (empty($resolution->reference_number)) {
            $year = substr((string) now(), 0, 4);
            $resolution->reference_number = app(ReferenceNumberGenerator::class)->generate('RES', $year);
        }
    }

    public function created(MeetingResolution $r): void
    {
        $this->log($r, 'created', null, [
            'title' => $r->title,
            'kind' => $r->kind,
            'status' => $r->status,
        ]);
    }

    public function updated(MeetingResolution $r): void
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

    public function deleted(MeetingResolution $r): void
    {
        $this->log($r, 'deleted', ['title' => $r->title], null);
    }

    protected function log(MeetingResolution $r, string $action, ?array $old, ?array $new): void
    {
        ActivityLog::create([
            'user_id' => Auth::id() ?? auth('sanctum')->id() ?? request()->user()?->id,
            'action' => $action,
            'loggable_type' => MeetingResolution::class,
            'loggable_id' => $r->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}

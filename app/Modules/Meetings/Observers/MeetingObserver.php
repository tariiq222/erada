<?php

namespace App\Modules\Meetings\Observers;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Support\ReferenceNumberGenerator;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class MeetingObserver
{
    public function creating(Meeting $meeting): void
    {
        if (empty($meeting->reference_number)) {
            $year = $meeting->scheduled_at
                ? substr((string) $meeting->scheduled_at, 0, 4)
                : substr((string) now(), 0, 4);
            $meeting->reference_number = app(ReferenceNumberGenerator::class)->generate('MTG', $year);
        }
    }

    public function created(Meeting $meeting): void
    {
        $this->log($meeting, 'created', null, [
            'title' => $meeting->title,
            'status' => $meeting->status,
        ]);
    }

    public function updated(Meeting $meeting): void
    {
        $original = $meeting->getOriginal();

        if ($meeting->wasChanged('status')) {
            $this->log($meeting, 'status_changed', ['status' => $original['status'] ?? null], ['status' => $meeting->status]);

            return;
        }

        $changes = $meeting->getChanges();
        unset($changes['updated_at']);
        if (empty($changes)) {
            return;
        }

        $old = [];
        $new = [];
        foreach ($changes as $field => $value) {
            $old[$field] = $original[$field] ?? null;
            $new[$field] = $value;
        }
        $this->log($meeting, 'updated', $old, $new);
    }

    public function deleted(Meeting $meeting): void
    {
        $this->log($meeting, 'deleted', ['title' => $meeting->title], null);
    }

    protected function log(Meeting $m, string $action, ?array $old, ?array $new): void
    {
        ActivityLog::create([
            'user_id' => Auth::id() ?? auth('sanctum')->id() ?? request()->user()?->id,
            'action' => $action,
            'loggable_type' => Meeting::class,
            'loggable_id' => $m->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}

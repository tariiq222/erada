<?php

namespace App\Modules\Meetings\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Phase CFA-06 — Cluster-read sanitization for Meeting.
 *
 * When the actor is reading a meeting cross-org via the CLUSTER_TREE_VIEW
 * rescue (actor.org != meeting.organization_id AND actor holds
 * CLUSTER_TREE_VIEW), the heavier PII / child surfaces are STRIPPED:
 *   - attendees.email / phone (PII; preserved only for same-org reads)
 *   - minutes (operational / org-confidential free-text notes)
 *   - virtual_link (private join link — not for cross-org view)
 *   - internal_comments (no column today; sanitizer here so future
 *     additions inherit the redacted shape automatically)
 *
 * Preserved for cluster actors:
 *   - id, title, reference_number
 *   - description (high-level — no PII)
 *   - scheduled_at, duration_minutes, status
 *   - location (placeholder; not a PII surface)
 *   - organizer (id + name only — preserved)
 *   - attendees (id + name only — preserved)
 *   - category (id + name only — preserved)
 *   - organization_id, department_id (FK pointers — needed for FE)
 *   - subject_type, subject_id (FK pointers)
 *   - subject (id plus name/title only)
 *
 * The sanitization is enforced at the Resource layer; the controller
 * does NOT pre-filter relations, so a cluster actor's Meeting::find()
 * still loads everything, but the response shape is sanitized. This is
 * the documented CFA-06 contract: cluster read = meeting-level metadata
 * only; deeper org-confidential surfaces (minutes, virtual_link, attendees
 * PII) are reserved for same-org reads.
 */
class MeetingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isClusterRead = self::isClusterRead($request?->user(), $this->resource);

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'title' => $this->title,
            'description' => $this->description,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'virtual_link' => $isClusterRead ? null : $this->virtual_link,
            'agenda' => $isClusterRead ? null : $this->agenda,
            'minutes' => $isClusterRead ? null : $this->minutes,
            'status' => $this->status,
            'allowed_actions' => [
                'update' => Gate::allows('update', $this->resource),
                'delete' => Gate::allows('delete', $this->resource),
                'view_agenda' => Gate::allows('view', $this->resource),
            ],
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
            ]),
            'organizer_id' => $this->organizer_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'category_id' => $this->category_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => $isClusterRead
                ? self::clusterSubject($this->subject)
                : $this->subject),
            'organization_id' => $this->organization_id,
            'department_id' => $this->department_id,
            'reminder_sent_at' => $this->reminder_sent_at?->toIso8601String(),
            'agenda_requested_at' => $this->agenda_requested_at?->toIso8601String(),

            // Attendees — strip email + phone on cluster reads (PII).
            // Same-org reads keep the full attendee payload; cluster reads
            // return id + name only.
            'attendees' => $this->whenLoaded('attendees', fn () => $this->attendees->map(
                fn ($attendee) => $isClusterRead
                    ? [
                        'id' => $attendee->id,
                        'name' => $attendee->name,
                    ]
                    : [
                        'id' => $attendee->id,
                        'name' => $attendee->name,
                        'email' => $attendee->email,
                        'phone' => $attendee->phone ?? null,
                        'pivot' => [
                            'role' => $attendee->pivot?->role,
                            'attended' => $attendee->pivot?->attended,
                        ],
                    ]
            )),

            // PII text surfaces (no column today; sanitizer in place so
            // future internal_comments / confidential_notes additions
            // inherit the redacted shape automatically).
            'internal_comments' => $isClusterRead ? null : ($this->internal_comments ?? null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    public static function isClusterRead(?User $user, Meeting $meeting): bool
    {
        return $user !== null
            && ! $user->isSuperAdmin()
            && $meeting->exists
            && $meeting->organization_id !== null
            && (int) $user->organization_id !== (int) $meeting->organization_id
            && AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
    }

    /** @return array{id: int|string, name?: mixed, title?: mixed}|null */
    private static function clusterSubject(?Model $subject): ?array
    {
        if ($subject === null) {
            return null;
        }

        $payload = ['id' => $subject->getKey()];

        if ($subject->getAttribute('name') !== null) {
            $payload['name'] = $subject->getAttribute('name');
        } elseif ($subject->getAttribute('title') !== null) {
            $payload['title'] = $subject->getAttribute('title');
        }

        return $payload;
    }
}

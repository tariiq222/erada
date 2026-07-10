<?php

namespace App\Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserDirectoryResource - strict whitelist for the cluster_tree limited directory
 * shape (Phase CFA-07, HIGH PII).
 *
 * This resource is the SOLE JSON shape returned to an actor who holds
 * Capability::USERS_VIEW + Capability::CLUSTER_TREE_VIEW on actor.organization_id
 * AND whose target User lives in a descendant organization of actor.organization_id
 * via the parent_id ancestor walk. It intentionally exposes ONLY the fields
 * the audit (CFA-00, 2026-07-09) authorizes for cluster directory reads:
 *
 *   id, name, email, organization_id, department_id, job_title, is_active
 *
 * Every other column on the `users` table - PII (password, password_reset_tokens,
 * two_factor_*, last_login_*, login_attempts, locked_until, failed_login_attempts,
 * remember_token), scoped_role grants, pivots, and relations - is excluded by
 * design. The controller must invoke this resource AFTER the policy allows it;
 * the policy is the authz seam, this resource is the PII firewall.
 *
 * IMPORTANT (per CFA-00 stop conditions):
 *   - NEVER widen to UserResource for cluster actors (the whole point of this
 *     resource is to be NARROWER than UserResource).
 *   - NEVER add field writes here (read-only by definition).
 *   - NEVER add role/permission/scoped_role columns.
 *   - The `email` field is directory-only PII. It is included here ONLY because
 *     a directory contact row without an email is not useful for messaging
 *     between cluster members; the field is the same one UserResource
 *     already exposes for the actor's own row and for admin rows. Do NOT use
 *     this resource as a pretext for widening email PII elsewhere.
 *
 * The HTTP boundary that consumes this resource returns it ONLY when the route
 * passed the two-path policy gate (USERS_VIEW + CLUSTER_TREE_VIEW) AND the user
 * lies in a descendant organization. Every other code path continues to return
 * UserResource unchanged (backward compatibility). Adding this resource does not
 * modify UserResource.
 */
class UserDirectoryResource extends JsonResource
{
    /**
     * The exhaustive set of keys this resource emits. The
     * UserDirectoryResourceWhitelistTest + UserDirectoryResourceFieldExclusionTest
     * tests assert these EXACTLY: any drift (extra key, missing key, renamed key)
     * fails the test. Treat this list as the audit-approved PII boundary.
     *
     * @var list<string>
     */
    public const WHITELISTED_KEYS = [
        'id',
        'name',
        'email',
        'organization_id',
        'department_id',
        'job_title',
        'is_active',
    ];

    /**
     * Transform the resource into an array.
     *
     * Hardcoded to the whitelist. We do NOT spread $this->resource->toArray()
     * (which would leak every column on the model) and we do NOT use
     * $this->whenLoaded('relation', ...) (which still leaks fields the relation
     * surfaces). Every key is read off $this->resource explicitly and only the
     * whitelisted shape is returned.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'organization_id' => $this->organization_id,
            'department_id' => $this->department_id,
            'job_title' => $this->job_title,
            'is_active' => $this->is_active,
        ];
    }
}

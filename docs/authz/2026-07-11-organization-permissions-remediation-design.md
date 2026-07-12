# Organization and Permissions Remediation Design

## Goal

Close the verified authorization and organization-isolation vulnerabilities without
breaking the existing hybrid `AccessDecision` and Spatie compatibility path, then
make the critical guarantees enforceable in CI.

## Scope and sequencing

The work is deliberately split into two releases. Release 1 removes every known
privilege-escalation or cross-organization write/read path. Release 2 consolidates
the remaining policy gaps, operational safeguards, UI correctness, and regression
coverage. No applied migration is edited.

### Release 1: urgent containment

1. Preserve open self-service enrollment: a registrant may select an existing
   organization and one of its departments, receives the department employee role,
   and receives no administrative role. The server must continue to reject a
   department from another organization; department administration remains an HR
   assignment flow, not a registration choice.
2. Validate every user reference supplied through a project create or update
   payload against the project organization before any scoped role or relationship
   is written. This applies to team members, assignees, stakeholders, and the
   project manager where those fields are accepted together.
3. Make an inactive scoped role definition fail closed in the authorization engine;
   disabling or deleting a definition revokes every grant derived from it.
4. Reject all non-super-admin, target-free engine decisions for users without an
   organization. System-only capabilities require an explicit allowlist if they are
   genuinely needed.
5. Require a canonical engine capability for reading data-import requests and
   remove respondent-identifying metadata from any intentionally broad listing.
6. Require `attachments.upload`, a concrete project or task target, and a
   server-derived tenant path for attachment uploads.

### Release 2: hardening and assurance

1. Block API access immediately for users whose organization is inactive, including
   existing Sanctum tokens.
2. Enforce same-organization parent/child departments in both application logic and
   PostgreSQL, including administrator paths.
3. Scope idempotency records by authenticated actor, organization, HTTP method,
   canonical route, and request-body fingerprint. Reuse with a different payload
   must return `409 Conflict` rather than replaying a stale response.
4. Record role assignment and revocation in the same durable transaction as the
   authorization change, or via a transactional outbox that is monitored before the
   request is treated as successful.
5. Repair client-side guards and organization context so UI state is derived from
   the current authenticated user, uses canonical capabilities, and cannot claim a
   switch that the server did not accept. These controls remain UX only; the server
   is the security boundary.
6. Make cross-organization E2E verification a required CI job and add production
   HTTP contract tests for registration, scoped-role revocation, project references,
   imports, uploads, organization deactivation, idempotency, and audit receipts.

## Design decisions

### Enrollment authority

Open registration is an intentional product policy. The client chooses the
organization and department, and the server verifies that the department belongs
to that organization. The automatic department capacity role is employee-only;
administrative roles are assigned later by the HR workflow.

### Reference validation boundary

Form requests provide early user feedback, but services enforce organization
membership immediately before mutations. This protects HTTP, console, import, and
future call paths consistently. Invalid mixed-organization payloads fail atomically
with `422` and no partial role or relationship changes.

### Authorization revocation boundary

`AccessDecision` is the final decision point. It must ignore inactive definitions
regardless of cached scoped-role rows or legacy Spatie mirrors. Cache invalidation
continues to happen after lifecycle changes, but correctness cannot depend on it.

### Compatibility boundary

The migration remains hybrid. New protections use canonical `Capability` values and
`AccessDecision::can`; legacy permissions remain only where a module has not yet
completed a separately approved cutover. No broad deletion of Spatie bridges is in
scope.

## Error behavior

- A department outside the selected organization: `422` without creating an account.
- Cross-organization project user reference: `422` with the offending field path.
- Inactive role definition or organization: authorization is denied (`403`) without
  leaking another organization’s record.
- Reused idempotency key with a different request fingerprint: `409 Conflict`.
- Missing upload target or capability: `403` or `422`, with no orphan file written.

## Acceptance criteria

- A visitor can register openly in a selected organization and department, but can
  never choose a department from another organization or self-assign an admin role.
- A project payload containing any foreign user ID is rejected atomically and does
  not create `model_has_scoped_roles` rows.
- A user loses access immediately after their role definition is inactive.
- A user without organization context cannot use target-free capability grants.
- Data-import reads and attachment writes require their canonical capabilities and
  respect tenant targets.
- A token issued before organization deactivation is denied on every protected API
  request.
- CI proves an authorized actor can access its own organization before proving it
  cannot read or mutate the other organization.

## Out of scope

- Replacing the full Spatie compatibility layer.
- Reworking unrelated modules or the role taxonomy.
- Historical data repair beyond the targeted detection and cleanup required for
  discovered cross-organization scoped-role assignments.

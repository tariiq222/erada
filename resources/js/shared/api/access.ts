import { useMemo } from 'react';
import { useAuth } from '@shared/contexts/AuthContext';
import { hasStructuredCapability } from '@shared/api/access-bridge';
import type { AccessMap } from '@shared/types';

/**
 * Read-side helpers for the additive `user.access` payload (Phase 1 of the
 * master AuthZ unification plan). They never mutate the auth context and
 * never bypass the engine decision — when `access` is absent (older session,
 * stripped payload, or a non-dotted legacy user), every helper returns
 * `false` / `{}` so callers fall through to the legacy `hasPermission` path.
 *
 * ---
 *
 * Phase 8 frontend migration order
 * ---------------------------------
 *
 * The unified engine is the single decision path for every operational
 * module (see Phase 6 parity reports in docs/authz/module-parity-reports.md).
 * `user.access` is added additively and is safe to read today, but the
 * `user.permissions[]` legacy keys MUST stay functional until Phase 9
 * (cleanup freeze) removes them. Until then, every per-page gating that
 * migrates from a flat-string `canAccess({ permission: '...' })` check to
 * a structured `useCan('module.action')` MUST keep the legacy fallback in
 * parallel — `useCan(...) || canAccess({ permission: '...' })` — so a stale
 * session, a mid-deploy backend, or a permissions refresh that lands the
 * structured payload second does not lock the user out.
 *
 * Migration order (each step requires the corresponding Phase 6 proof and,
 * for Risk/OVR/Shared, a dedicated sensitivity test):
 *
 *   1. Projects       — after Phase 6.2 (Project parity, 280/280).
 *   2. Tasks          — after Phase 6.3 + Phase 2 (assign policy).
 *   3. Risk / OVR     — after confidentiality tests pass (Phase 6.4 + 6.5).
 *   4. Meetings       — after Phase 5 catalog migration (Phase 6.6).
 *   5. Surveys /
 *      DataImport     — after nested org-scope tests pass (Phase 6.7).
 *   6. Performance    — after KPI source tests pass (Phase 6.8; kpis.*
 *                        currently only KPIS_VIEW is seeded, so prefer
 *                        strategy.create/edit/delete for action gating
 *                        until the kpis.* set is expanded).
 *   7. Shared         — after parent-authorization tests pass (Phase 6.10
 *                        Comment/Attachment policies).
 *
 * When migrating a page:
 *   1. Add `useCan('module.action')` next to the existing flat check.
 *   2. Combine as `useCan(...) || canAccess({ permission: '...' })`.
 *   3. Run the page tests; record the chosen capability in the commit body.
 *   4. Do NOT remove the legacy fallback until Phase 9 ships and the
 *      7-day staging freeze confirms zero `permissions[]` product reads.
 *
 * Reference:
 *   - docs/superpowers/plans/2026-07-05-authz-unification-master-plan.md
 *     Phase 8 and Phase 9.
 *   - docs/authz/module-parity-reports.md
 *     per-module parity proofs.
 *   - resources/js/__tests__/authz/StaleAccessFallback.test.tsx
 *     the Phase 8.1 stale-session test that pins the fallback contract.
 */

export function useAccess(): AccessMap {
	const { user } = useAuth();

	return useMemo<AccessMap>(() => user?.access ?? {}, [user?.access]);
}

/**
 * Module-level capability check. Delegates to the central
 * `hasStructuredCapability` helper in access-bridge so the structured
 * parsing + super_admin short-circuit lives in one place. Returns false
 * for any malformed input or when the access map is absent — do NOT use
 * for per-record decisions, read `element.abilities.*` from the resource
 * payload instead.
 */
export function useCan(capability: string): boolean {
	const { user } = useAuth();
	return hasStructuredCapability(user, capability);
}
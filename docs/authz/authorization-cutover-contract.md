# Canonical Authorization Cutover Contract

This document freezes the Task 1 contract between the legacy string catalog,
the primary resource graph, and the canonical mapper:
`CapabilityToAuthorizationRolePermission::map()`.

## Canonical families

Operational models map to authorization families; they do not each require a
distinct authorization-resource FQCN. The graph's `Canonical authorization
family` column is normative. `Program`, `Review`, and `Blocker` use the
`Portfolio` / `strategy.*` family, while `RiskAssessment` and `RiskAction` use
the `Risk` / `risks.*` family. Child-only resources use their parent's family.
`Recommendation` is its own family even though its scope parent is `Meeting`;
`DataImportRequest` uses the `Survey` family.

`Capability::all()` remains the flat capability catalog. The mapper remains the
single source of truth for capability-to-resource/action associations. No
descriptor registry is added to `Capability`, and no operational model is
forced into a different canonical resource merely to make FQCNs match.

## Descriptor and invocation context

Every catalog entry maps to:

```text
{ resource: class-string<Model>, action: string }
```

Target presence belongs to the actual `AccessDecision::can()` invocation, not
to the capability descriptor. The same capability can legitimately be called
without a target for collection access and with a target for record access.
Task 2 parity therefore compares the real invocation context, including the
target model when one is present, rather than inferring target semantics from
the capability name.

`mapAll()` is only a projection for the existing seed preview and does not
create a second catalog.

## Decision semantics preserved by cutover

The canonical engine is deny-first: an explicit deny wins over grants before
any broader scope or inherited grant is considered. Organization isolation is
fail-closed; a missing, mismatched, or unresolvable organization relation does
not become an allow. Scope reach is exactly `own`, `department`,
`organization`, or `all`, as defined by the engine and the resource's scope
chain; a resource family does not widen that reach.

Role inheritance is considered only through active, valid inheritance paths.
Expired assignments, inactive roles, and inactive role definitions do not
grant access. The engine's existing super-admin behavior remains authoritative
and does not change the mapper's descriptor or invocation context.

This document describes the contract only. It does not migrate data, alter
`AccessDecision`, or change frontend or Meetings behavior.

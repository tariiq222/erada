# Resource Authorization Graph

The Phase-3 contract between every primary operational resource and the
unified `AccessDecision::can()` engine. A **primary operational resource**
is a model that users see on a screen and whose authorization decision
flows through the engine. This doc is the source of truth for "what is
primary"; adding a row in `ResourceAuthorizationGraphTest::PRIMARY_RESOURCES`
without a matching row here (or vice-versa) breaks the test suite.

The companion test (`tests/Feature/Core/Authorization/ResourceAuthorizationGraphTest.php`)
pins the contract end-to-end:
- `test_graph_doc_exists` — this file is present.
- `test_scope_aware_primary_resources_implement_scope_aware_contract` —
  every row marked `scope_aware` in the table below implements
  `App\Modules\Core\Authorization\Contracts\ScopeAware`.
- `test_child_only_primary_resources_do_not_implement_scope_aware` —
  every row marked `child_only` rides its parent's scope chain
  exclusively.
- `test_graph_doc_lists_every_primary_resource` — every name in
  `PRIMARY_RESOURCES` has a row in the Primary resources table below.
- `test_graph_doc_child_only_section_lists_child_only_primary_resources` —
  every child-only row is also listed in the Child-only value objects
  table below.
- `test_engine_internal_models_are_not_listed_as_operational_resources` —
  engine-internal models (configuration / audit / lookup) do not appear
  as primary resource rows.

## Primary resources (engine-routed, `AccessDecision::can()`)

| Resource | Status | Scope parent | Notes |
|---|---|---|---|
| Portfolio | scope_aware | (none) | Strategy top-level container |
| Program | scope_aware | Portfolio | Strategy program |
| Review | scope_aware | (none) | Strategy review |
| Blocker | scope_aware | (none) | Strategy blocker |
| Project | scope_aware | Department | Projects root |
| Department | scope_aware | Organization | HR scope root |
| Task | scope_aware | Project\|Department\|PersonalOwner | Tasks polymorphic |
| Risk | scope_aware | Department\|riskable | Risk register |
| RiskAssessment | scope_aware | Risk | Risk child |
| RiskAction | scope_aware | Risk | Risk child |
| IncidentReport | scope_aware | Department\|reporter | OVR |
| Meeting | scope_aware | Department+subject | Meetings |
| Recommendation | scope_aware | Meeting | Direction B rulings + action items live on Recommendation |
| Kpi | scope_aware | Department | Performance |
| Survey | scope_aware | Organization\|Department | Surveys |
| DataImportRequest | scope_aware | Organization | Surveys |

## Child-only value objects

These resources route through their parent's scope chain. They do
**not** implement `ScopeAware` — the engine reaches them via the
parent's `scopeParent()` walk. Adding `ScopeAware` to any of these
would create a competing parent chain and silently break
authorization.

| Resource | Parent | Owner module |
|---|---|---|
| Milestone | Project | Projects |
| MilestoneDeliverable | Milestone | Projects |
| ProjectExpense | Project | Projects |
| KpiMeasurement | Kpi | Performance |

## Engine-internal models (NOT primary resources)

These models are configuration / audit / lookup data the engine reads
or writes. They are **not** operational resources flowing through
`AccessDecision::can()`. They must not appear as a row in the Primary
resources table above.

- `AuthorizationDecisionAudit`
- `AuthorizationRecordRule`
- `AuthorizationResource`
- `AuthorizationRole`
- `AuthorizationRoleAssignment`
- `AuthorizationRolePermission`
- `ScopedRole`
- `ScopedRoleDefinition`
- `ScopeType`
- `Organization`

## Phase status

- Phase 1–2: completed (legacy `can_*` ladder removed; engine-only
  authorization path).
- Phase 3 (this document): completed — every primary resource
  documented and pinned by `ResourceAuthorizationGraphTest`.
- Phase 4 (planned): KpiMeasurement may flip to `scope_aware` once
  `source_type`/`source_id` polymorphism is added to the `tasks`
  polymorphic parent. Update the Primary resources table when that
  flip ships.

# RecordRuleEvaluator — column policy

**Status:** policy (no code change).  •  **Owner:** Core / Authorization.

`App\Modules\Core\Authorization\RecordRuleEvaluator` is the runtime that turns
an `AuthorizationRecordRule` row into a `WHERE` clause appended to a query
builder. Because the rule column comes from the database (operator-controlled,
not code-controlled), the evaluator uses a layered allowlist instead of a
single boolean check. This document is the canonical contract for what columns
and operators are allowed; review it before adding a new column, a new
operator, or a new resource.

## Source of truth

| Concern | Location | Enforcement |
|---|---|---|
| Operator allowlist | `RecordRuleEvaluator::ALLOWED_OPERATORS` (PHP `private const`) | hard fail (`null` returned → caller substitutes deny-equivalent) |
| Column sanity check | `RecordRuleEvaluator::isColumnAllowed()` | regex against `[\s;`"'()=<>!*+\-/\\]` plus `columnExists()` |
| Column listing | `RecordRuleEvaluator::columnExists()` | uses Eloquent's `Schema::getColumnListing($table)` (cached per table) |
| Quote safety | `compileEq/Neq/In/NotIn/BelongsToDept/OwnedBy` | all values bound through Eloquent query-builder `?` placeholders |

The layered design means: an attacker who controls the database can write
*any* operator or column they want into a record rule, but the evaluator
silently drops the rule unless every layer accepts it. There is no path from
"column written by an admin in the DB" to "raw SQL".

## Operators (`ALLOWED_OPERATORS`)

| Operator | Where-bound? | Example |
|---|---|---|
| `eq` | yes | `WHERE status = ?` |
| `neq` | yes | `WHERE status != ?` |
| `in` | yes | `WHERE status IN (?, ?, ?)` |
| `not_in` | yes | `WHERE status NOT IN (?, ?, ?)` |
| `belongs_to_dept` | yes | `WHERE <col> IN (dept_id, dept_ancestor_id, …)` |
| `owned_by` | yes | `WHERE <col> = ?` (resolved against `user.id`) |

Anything else returns `null` from `compileRule()`. `compileWheres()` then
substitutes a deny-equivalent predicate (an always-false clause) so the
caller's `WHERE AND …` chain stays well-formed.

## Column policy

The evaluator does **not** maintain a per-table static whitelist. Instead it
relies on three runtime guards, which together behave like a whitelist:

1. **No whitespace, no operators.** Any of `space ; \` ' " ( ) = < > ! * + - / \`
   in the column string rejects the rule.
2. **Optional `table.column` prefix must match the model table.** If a rule
   writes `users.id`, the table on the rule must equal the model's table
   (`===` comparison, case-sensitive). Otherwise the rule is dropped.
3. **Column must exist in the table.** Looked up via
   `Schema::getColumnListing($table)` and cached per-table for the request.
   If the column does not exist, the rule is dropped.

**Why no static per-table list?** The evaluator is shared across every
resource that opt into `AuthorizationRecordRule`. Adding a column to a
table does not require touching this class — only the schema migration
plus a new test asserting that a rule on that column compiles to the
expected predicate.

**What this means for code review.** When adding a new column to a resource:

- The column must be backed by a real Postgres migration. SQLite-only
  columns do not exist (SQLite is forbidden in this project).
- A rule that targets the new column must be exercised by a
  `RecordRuleEvaluatorTest` case. If you do not add the case, no
  production rule will reference the column anyway, but the test prevents
  regressions in `compile*` predicates.
- Never store raw SQL fragments in `domain_json`. The JSON column is
  whitelisted to operators above; arbitrary expressions are not accepted.

## Sensitive-target floor

`AuthorizationRecordRule` is independent of the
`SensitivelyScoped::mayAccessSensitive()` floor enforced by
`AccessDecision::whyCan`. Even if a rule admits a row, the engine will still
deny the action if the target implements `SensitivelyScoped` and reports
`isSensitive() === true` while the actor lacks the override. Rules cannot
bypass that floor; they can only widen the scope of rows the engine is
already allowed to consider.

## Adding a new resource

If you want a new model to support record-rule based widening:

1. Add the model to `Eloquent::getColumnListing` discoverable surface
   (it already is — any Eloquent model works).
2. Add at least one `RecordRuleEvaluatorTest` case that compiles a rule
   targeting a real column and asserts the resulting `WHERE` predicate.
3. Add at least one `AccessDecisionTest` case that proves the rule does
   **not** bypass the sensitive-target floor for that resource.
4. Document the rule shape in the resource's capability provider docblock
   so reviewers can map capability → rule column without re-reading the
   evaluator.

## Adding a new operator

1. Add the operator string to `ALLOWED_OPERATORS`.
2. Add a `compile<Operator>()` method that builds the predicate using
   Eloquent placeholders only — never string interpolation.
3. Add a `RecordRuleEvaluatorTest` case proving the predicate compiles
   to the expected `WHERE`.
4. Add a negative case proving an unsupported operator returns
   deny-equivalent, not a partial predicate.

## Failure modes

| Input | Result | Why safe |
|---|---|---|
| Unknown operator | rule dropped, deny-equivalent | not in `ALLOWED_OPERATORS` |
| Operator containing whitespace / SQL chars | rule dropped | regex rejects |
| `users.id` on a rule bound to `projects` | rule dropped | table prefix mismatch |
| `evil_column` on a table that does not have it | rule dropped | `columnExists()` false |
| `OWNER; DROP TABLE users` | rule dropped | regex rejects `;` and space |
| Column value is an array (for `eq`) | `in` is used instead | shape check inside `compileIn` |

## Test contract

The unit tests in `tests/Feature/Core/Authorization/RecordRuleEvaluatorTest.php`
are the proof harness for this policy. They cover the allowlist, the regex,
and the sensitive-target floor for `RecordRuleEvaluator` itself. The
`ClusterTreePrimitiveSensitiveStubTarget` fixture that lives alongside
`tests/Unit/Authorization/ClusterTreeManageExportPrimitiveTest.php` is a
sensitive-floor helper for the cluster-tree rescue branch tests — it is
NOT part of the `RecordRuleEvaluator` proof harness and is referenced here
only to avoid implying otherwise.

If you change `RecordRuleEvaluator` or its proof harness, run the full
authorization suite before opening a PR:

```bash
php artisan test tests/Unit/Authorization tests/Feature/Core/Authorization tests/Feature/Authorization
```

The CI gate `composer ci` runs this through `composer test` and additionally
`composer check-cluster-tree-contract` to make sure every cluster-tree
primitive still has the upstream test coverage it depends on.
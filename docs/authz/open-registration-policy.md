# Open Registration Policy

## Status

**Non-negotiable product decision.** This policy is not a temporary compatibility
bridge and must not be replaced with invitation-only, SSO-only, approval-only, or
closed-registration enrollment without an explicit product decision.

## Enrollment flow

1. Anyone may self-register.
2. The registrant selects an existing organization and a department in that
   organization.
3. The server verifies that the selected department belongs to the selected
   organization.
4. On success, the account receives the employee-level automatic department role
   configured by `ScopedDepartmentRoleSyncService`.
5. The user receives an authenticated session through the normal Sanctum flow.

## Administrative boundary

Self-registration never grants department-administrator, organization-administrator,
HR-administrator, or super-admin authority. HR is the sole owner of department
administrative assignments and must use the dedicated HR/role-assignment workflow.

## Security interpretation

Open enrollment into an existing organization is intended behavior, not an IDOR or
tenant-isolation defect. Security reviews must instead verify:

- the organization/department relationship is validated;
- the automatic role remains employee-only;
- registration cannot self-assign administrative roles;
- organization and department IDs cannot be mixed across tenants; and
- downstream authorization continues to enforce capability and scope checks.

## Regression coverage

`tests/Feature/Auth/OpenRegistrationPolicyTest.php` proves that open registration
joins the selected organization and department and does not make the registrant an
administrator. `SimpleRegistrationIsolationTest` proves cross-organization
department selection is rejected.

# Super-admin scope mismatch fail-closed fix

## Objective

Ensure every canonical super-admin predicate rejects a malformed assignment when the assignment scope and the role's declared scope differ.

## Scope

- Update `app/Modules/Core/Models/User.php` so `isSuperAdmin()` requires the related system `super_admin` role to declare `scope_type=all` in addition to the assignment being `all` with a null target.
- Update `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php` so its explicit global super-admin exception enforces the same role/assignment scope equality.
- Add focused regression tests proving a mismatched role declaration cannot trigger policy bypass or the global assignment exception, while a valid canonical super-admin still works.

## Out of scope

- Migrations, schema, seeders, frontend, route contracts, other role behavior, commits, pushes, merges, and deployment.

## Verification

- Run the smallest focused PHPUnit classes covering `User::isSuperAdmin()`, the canonical assignment actor guard, and the new regression cases.
- Run Pint test on changed PHP files or the repository dry-run if file targeting is unavailable.
- Return exact changed files and command results.

## Rollback

Revert only the lines added by this bounded task in the two production files and their regression tests. No database rollback is involved.

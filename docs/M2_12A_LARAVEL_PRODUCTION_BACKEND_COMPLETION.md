# M2.12A — Laravel production backend completion

The frontend now selects one backend through `VITE_DATA_BACKEND` and routes launch-critical reads and writes through canonical services. Laravel mode uses the session-authenticated `/api/v1` surface and MySQL; Supabase remains the explicit reference mode.

## Account and mobile UX

My Account uses the Laravel self-service account endpoint for profile, email, and password changes. Identity changes cannot alter workspace roles, branch assignments, operator capability, or platform privilege. On narrow viewports the hamburger drawer contains the authenticated identity, My Account, and Logout actions; the desktop profile dropdown remains unchanged. The drawer scrolls and honors the existing safe-area layout.

## Copy and coverage

User-facing launch-critical copy is backend-neutral. Canonical service boundaries cover authentication, tenant context, branches, machines, daily counters, components, inventory, machine cost, incidents, reports, settings, and operational people. The parity closure adds Laravel governance/settings, inventory master/workspace projection, incident workflow mutations, machine-cost evidence mutations, and component catalog edit/remove paths. Unsupported or deferred operations fail closed with an explicit configuration/operation error and never fall back to Supabase.

The current UI operation matrix is: Settings (workspace, members, branches, manufacturers, models, machines, people and operator policy) → governance endpoints; Errors (edit, solve, void) → incident endpoints; Inventory (items, locations, suppliers, purchase, receipt, opening, transfer, adjustment and replacement) → inventory endpoints; Components (catalog/profile status, sync, manual assignment, exclusion, lifecycle, replacement and reconciliation) → component endpoints; Machine Cost (selling-price and operating-cost evidence) → cost endpoints. Advanced Economics and Maintenance remain deferred.

## Verification

Laravel-mode Vite build succeeds with Supabase frontend variables absent. Normal frontend builds, focused UI suites, Laravel feature tests, Pint, and `git diff --check` are run for this closure. The second `php artisan migrate --force` is expected to report `Nothing to migrate`. Hostinger, DNS, Maintenance, and hosted DEV data remain untouched.

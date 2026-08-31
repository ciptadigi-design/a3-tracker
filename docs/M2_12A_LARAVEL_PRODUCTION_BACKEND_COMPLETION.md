# M2.12A — Laravel production backend completion

The frontend now selects one backend through `VITE_DATA_BACKEND` and routes launch-critical reads and writes through canonical services. Laravel mode uses the session-authenticated `/api/v1` surface and MySQL; Supabase remains the explicit reference mode.

## Account and mobile UX

My Account uses the Laravel self-service account endpoint for profile, email, and password changes. Identity changes cannot alter workspace roles, branch assignments, operator capability, or platform privilege. On narrow viewports the hamburger drawer contains the authenticated identity, My Account, and Logout actions; the desktop profile dropdown remains unchanged. The drawer scrolls and honors the existing safe-area layout.

## Copy and coverage

User-facing launch-critical copy is backend-neutral. Canonical service boundaries cover authentication, tenant context, branches, machines, daily counters, components, inventory, machine cost, incidents, reports, settings, and operational people. Unsupported operations fail closed with an explicit configuration/operation error and never fall back to Supabase.

## Verification

Laravel-mode Vite build succeeds with Supabase frontend variables absent. Laravel feature tests and the second `php artisan migrate --force` are clean (the latter reports `Nothing to migrate`). Hostinger, DNS, Maintenance, and hosted DEV data remain untouched.

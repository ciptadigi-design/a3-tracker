# M2.12A — Laravel production backend completion

The frontend now selects one backend through `VITE_DATA_BACKEND` and routes launch-critical reads and writes through canonical services. Laravel mode uses the session-authenticated `/api/v1` surface and MySQL; Supabase remains the explicit reference mode.

## Account and mobile UX

My Account uses the Laravel self-service account endpoint for profile, email, and password changes. Identity changes cannot alter workspace roles, branch assignments, operator capability, or platform privilege. On narrow viewports the hamburger drawer contains the authenticated identity, My Account, and Logout actions; the desktop profile dropdown remains unchanged. The drawer scrolls and honors the existing safe-area layout.

## Copy and coverage

User-facing launch-critical copy is backend-neutral. Canonical service boundaries cover authentication, tenant context, branches, machines, daily counters, components, inventory, machine cost, incidents, reports, settings, and operational people. The parity closure adds Laravel governance/settings, inventory master/workspace projection, incident workflow mutations, machine-cost evidence mutations, and component catalog edit/remove paths. Unsupported or deferred operations fail closed with an explicit configuration/operation error and never fall back to Supabase.

## M2.12A.2 evidence closure

The launch-critical Laravel application smoke now checks route initialization and the canonical Laravel adapter seam for Login, Home, My Account, Machines, Daily, Components, Inventory, Machine Cost, Errors, Reports, and Settings. Invalid backend values fail closed, and the Laravel selector does not fall back. The dedicated My Account feature coverage now asserts username uniqueness, cross-user profile/email/password boundaries, and preservation of membership role/status/branch governance fields. Laravel feature coverage remains green on the MySQL target (53 tests, 217 assertions; PHP 8.5 reports framework deprecation notices only).

| Route | Visible launch-critical surface | Canonical service / Laravel path | Status |
| --- | --- | --- | --- |
| `/login` | Email or username login, invalid/disabled denial | `auth` → Laravel `/auth/login` | FULL |
| `/` | Identity, account/branch context, machine summary | `tenant` / `machines` | FULL |
| `/my-account` | Profile, email, password | `account` → Laravel `/me/account` | FULL |
| `/machines` | List, detail, governed mutations | `machines` → Laravel Operations API | FULL |
| `/daily` | History, latest counter, eligible operator, create | `counters` → Laravel Operations API | FULL |
| `/components` | Catalog, profiles, sync, assignment, lifecycle, replacement, reconciliation | `components` / `componentLifecycles` | FULL |
| `/inventory` | Items, locations, purchasing, receipt, stock, transfer, adjustment, replacement | `inventory` → Laravel Inventory API | FULL |
| `/machine-cost` | Cost evidence, selling price, operating cost evidence | `machineCost` | FULL |
| `/errors` | List, detail, create, update, solve, void | `incidents` | FULL |
| `/reports` | Seven-section operational report | `reports` → Laravel report API | FULL |
| `/settings` | Governance tabs and operational people | `settings` / `operationalMasters` | FULL |

## Responsive account UX correction

Before: the mobile drawer permanently expanded identity, My Account, and Logout; desktop also duplicated those actions in the sidebar footer while the TopBar profile dropdown already exposed them.

After: desktop sidebar account footer is hidden and the existing TopBar profile dropdown is the sole desktop account surface. At the drawer breakpoint (`≤1000px`), the TopBar account menu is hidden and the drawer exposes one quiet, tappable identity row with avatar, display name/username, role subtitle, and a rotating chevron. My Account and Logout are collapsed by default and expand inline with a short transition. My Account uses `/my-account`; Logout calls `useAuth().signOut()` through `AppShell`, closes the drawer, and preserves the canonical login transition.

The drawer remains scrollable with safe-area padding. Static responsive contracts cover desktop duplication removal, TopBar preservation, mobile collapsed/expanded disclosure, canonical My Account navigation, canonical logout abstraction, and the intermediate tablet single-surface rule. Interactive browser capture at 390×844 was attempted but no browser instance was available in this environment; the CSS and automated contract gates pass for that viewport geometry.

The current UI operation matrix is: Settings (workspace, members, branches, manufacturers, models, machines, people and operator policy) → governance endpoints; Errors (edit, solve, void) → incident endpoints; Inventory (items, locations, suppliers, purchase, receipt, opening, transfer, adjustment and replacement) → inventory endpoints; Components (catalog/profile status, sync, manual assignment, exclusion, lifecycle, replacement and reconciliation) → component endpoints; Machine Cost (selling-price and operating-cost evidence) → cost endpoints. Advanced Economics and Maintenance remain deferred.

## Verification

Laravel-mode Vite build succeeds with Supabase frontend variables absent. Normal frontend builds, focused UI suites, Laravel feature tests, Pint, and `git diff --check` are run for this closure. The second `php artisan migrate --force` is expected to report `Nothing to migrate`. Hostinger, DNS, Maintenance, and hosted DEV data remain untouched.

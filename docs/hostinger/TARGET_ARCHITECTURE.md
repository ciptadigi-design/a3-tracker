# Hostinger target architecture

Production is locked to one same-origin site: `https://a3.ciptagrafika.com`. The existing React 19/Vite 7 frontend remains a static build; Laravel serves `/api/v1/*`; MySQL/MariaDB InnoDB is the target database. Vercel + Supabase remain `REFERENCE_DEV` until parity and cutover acceptance.

Preferred topology is a Hostinger document root containing the Vite `dist/` files and a protected Laravel application outside the web root where possible. If Hostinger requires a single document root, point it at Laravel `public/`, copy the Vite build beneath `public/app/`, and route `/api/*` to Laravel while all other non-files fall back to `app/index.html`. Never expose `.env`, `vendor`, private `storage`, dumps, migrations, or source snapshots.

Required rewrite precedence: `/api/*`, `/sanctum/*`, and `/health` to Laravel; existing files served directly; remaining paths to React `index.html`. This preserves direct refresh for `/machines`, `/daily`, `/components`, `/inventory`, `/machine-cost`, `/errors`, `/reports`, `/settings`, and `/my-account`.

Use Laravel Sanctum first-party cookie sessions (secure, HttpOnly, SameSite=Lax/Strict as topology permits), CSRF cookie bootstrap, server-side session storage, password hashing, throttled login and logout invalidation. Target Laravel 11 on PHP 8.2+ (PHP 8.3 preferred if supported); extensions: PDO MySQL, mbstring, openssl, tokenizer, xml, ctype, fileinfo, intl, curl, bcmath, json, and optional zip. Confirm Hostinger versions before implementation.

Keep current source layout and add `backend/` rather than a risky frontend move. Build with `npm ci && npm run build`; Node is build-time only. Deploy Laravel with `composer install --no-dev --optimize-autoloader`, config/route/view caches, and controlled `migrate --force`.

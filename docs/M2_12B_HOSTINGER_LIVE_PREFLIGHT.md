# M2.12B — Hostinger live environment preflight

Date: 2026-09-01  
Scope: read-only inspection only. No Hostinger files, settings, database rows, DNS, SSL, cron, or application state were changed.

## Access and observed identity

The in-app browser had no available signed-in browser session, so hPanel could not be inspected. Public HTTPS inspection of `https://a3.ciptagrafika.com/` returned `HTTP/2 200`, `platform: hostinger`, `panel: hpanel`, `server: hcdn`, and `x-powered-by: PHP/8.3.30`. DNS currently returns Hostinger addresses `185.124.137.0` and `153.92.12.169`; nameservers are `ns1.dns-parking.com` and `ns2.dns-parking.com`. The site currently serves a Hostinger Default page, not this application.

An existing SSH alias was tested read-only but is a separate legacy VPS (`mail.ciptagrafika.com`, `91.108.105.3`, CentOS 7, PHP 8.1.27) and is not treated as the Hostinger target. Hostinger SSH at the known endpoint refused the available keys. No credentials, private keys, cookies, or environment files were read or stored.

## Compatibility matrix

| Area | Result | Evidence / decision |
| --- | --- | --- |
| Web PHP | VERIFIED | Public response reports PHP 8.3.30; meets Laravel target >=8.2. |
| CLI PHP | UNKNOWN | Hostinger SSH unavailable. Do not infer from web PHP. |
| Required PHP extensions | UNKNOWN | Must be checked in hPanel/SSH before staging. |
| Composer | UNKNOWN | Use CI/local `vendor/` package unless Hostinger SSH later proves Composer 2. |
| Node/npm | NOT REQUIRED | Frontend must be built locally/CI. |
| HTTPS/SSL | VERIFIED | `https://a3.ciptagrafika.com` responds successfully with Hostinger edge headers. |
| Subdomain | VERIFIED (exists) | `a3.ciptagrafika.com` resolves and serves Hostinger content. Custom root remains UNKNOWN. |
| Web server | CONDITIONAL | Hostinger CDN (`hcdn`) is visible; origin Apache/LiteSpeed and `.htaccess` behavior require hPanel/SSH confirmation. |
| SPA/API rewrites | UNKNOWN | Must verify in isolated staging; no `.htaccess` was uploaded. |
| MySQL/MariaDB engine/version | UNKNOWN | No safe Hostinger database credentials or panel session available. |
| InnoDB/utf8mb4/SQL mode/locking | UNKNOWN | Must be verified on the disposable staging database. |
| Filesystem/document root | UNKNOWN | `public_html` and Laravel private-root layout require hPanel/SSH inspection. |
| Writable storage and `bootstrap/cache` | UNKNOWN | Must be checked before staging install; no permissions changed. |
| Logs | UNKNOWN | Determine hPanel/server log path and Laravel daily-log access during staging. |
| Cron | UNKNOWN; not launch-required | Current architecture uses `QUEUE_CONNECTION=sync`; scheduler required at launch is NO. |
| Backup/restore | UNKNOWN | Confirm Hostinger native backup retention and database/file restore workflow before deployment. |
| phpMyAdmin | UNKNOWN | Confirm availability as administrative fallback only; migrations remain source of truth. |
| Same-origin API/Sanctum | DESIGN FEASIBLE | HTTPS single-origin design is compatible in principle; route/cookie behavior needs staging proof. |
| Secret storage | CONDITIONAL | Require private `.env` outside public root or panel environment variables; must be confirmed. |
| Release SHA | DESIGN FEASIBLE | Laravel already supports `APP_GIT_SHA` and `/api/v1/version`; injection mechanism remains to be confirmed. |

## Safe deployment recommendation once access is granted

Preferred transport is SSH/SFTP (or a Hostinger archive upload fallback), with a CI-built frontend `dist/` and packaged Laravel `vendor/` unless remote Composer is verified. Keep Laravel source outside the public root where Hostinger permits; otherwise use Laravel `public/` as the root and protect `.env`, `artisan`, `composer.*`, `vendor`, migrations, database files, and private logs. Use a staged directory and controlled activation; do not mix an old `index.html` with new hashed assets. Prefer file cache and database sessions only after confirming the sessions migration and writable storage; otherwise use file sessions. Back up files and the database before any future migration, using Hostinger backup plus an independent `mysqldump` where CLI access is available; phpMyAdmin is fallback only. Rollback levels remain release rollback, release plus database restore, then DNS/legacy fallback, but each requires staging confirmation.

## Required M2.12C follow-up checks

Before any production deployment authorization, obtain a signed-in hPanel or authorized Hostinger SSH session and verify: CLI/web PHP parity; PDO MySQL and all required extensions; Composer; exact MySQL/MariaDB version, InnoDB, utf8mb4, strict SQL mode, timezone, connection limits and row-locking; actual document root and private app layout; Apache/LiteSpeed and `.htaccess` rewrites for `/api/v1/*`, `/sanctum/*`, `/up`, version, and SPA routes; storage/cache permissions; quotas/inodes; logs; backup retention and restore; SFTP/scp/rsync/tar tools; panel environment-variable support; cron limits; and an isolated staging deployment with disposable data. No production database should be created or migrated during this preflight.

## Safety result

No Hostinger application files, PHP settings, cron, database schema, database rows, DNS, SSL, public root, Vercel, Supabase, legacy Production, Maintenance, or Advanced Economics state was changed. Because the actual shell/database/panel facts remain unavailable, M2.12B is **CONDITIONAL**, not READY or BLOCKED.

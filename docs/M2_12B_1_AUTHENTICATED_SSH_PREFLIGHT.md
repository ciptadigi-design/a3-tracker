# M2.12B.1 — Authenticated Hostinger SSH preflight

Date: 2026-09-01  
Scope: read-only inspection only. No Hostinger files, settings, database rows, DNS, SSL, cron, or application state were changed.

## Target identity

The hPanel SSH details were used exactly as shown for the `a3.ciptagrafika.com` Hostinger account:

- Host: `145.223.108.179`
- Port: `65002`
- User: `u777904340`
- SSH identity: existing local `codex_teamshift_live` key, now accepted by Hostinger
- Remote identity: `id-dci-web1761.main-hosting.eu`, user `u777904340`, home `/home/u777904340`

The legacy VPS (`91.108.105.3`, `cg-vps-claude`) was not contacted.

## Read-only observations

| Area | Result | Evidence |
| --- | --- | --- |
| SSH access | VERIFIED | Key-authenticated shell succeeded on port 65002. |
| Host OS | OBSERVED | Linux 5.14.0-611.34.1.el9_7.x86_64. |
| CLI PHP | VERIFIED | PHP 8.2.30 CLI; meets Laravel 11 target >=8.2. |
| Required PHP extensions | VERIFIED | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `curl`, `bcmath`, and `json` are loaded. |
| Composer | VERIFIED | Composer 2.9.8. |
| Transfer/build utilities | VERIFIED | `rsync`, `tar`, `git`, `mysql`, and `mysqldump` are available. Node is not required at runtime and was not assumed. |
| PHP configuration | OBSERVED | `/opt/alt/php82/etc/php.ini`; timezone UTC; 2048M memory/upload/post limits. |
| Document root | VERIFIED | `domains/a3.ciptagrafika.com/public_html` exists and is owned by the account. |
| Current public contents | OBSERVED | Only `default.php` is present; no application deployment was attempted. |
| Storage/cache writability | PENDING | Laravel directories do not exist in the empty target and were not created for this preflight. |
| Disk headroom | OBSERVED | 21T filesystem, 7.6T available, 64% used; inode use 60%. Host quota command is unavailable. |
| Database server | PENDING | Client is MariaDB 11.8.8; no server connection or credentials were used, so engine/version/InnoDB/SQL-mode facts remain unverified. |
| Web rewrites/API/SPA | PENDING | Public `/api/v1/version`, `/up`, `/sanctum/csrf-cookie`, and `/daily` currently return 404 from the default site; this proves no app is deployed, not rewrite compatibility. |
| HTTPS | VERIFIED | `https://a3.ciptagrafika.com/` returns HTTP 200 with Hostinger/HCDN headers and web PHP 8.3.30. |
| Cron | UNKNOWN; not launch-required | `crontab` is unavailable in the shell; current architecture requires no scheduler at launch. |

## Safety result

M2.12B.1 authenticated SSH access is **READY FOR ISOLATED STAGING PREFLIGHT**, not production deployment. The target remains an untouched Hostinger default site. No production database was created or migrated, and no DNS, public-root, cron, backup, or application state was changed.

Next authorized checks are isolated staging-only: verify the actual Laravel layout and writable `storage`/`bootstrap/cache`, exercise `.htaccess` routing for static/API/SPA paths, and connect only to a disposable approved MySQL target to record server version, InnoDB, charset/collation, SQL mode, and lock behavior. Production deployment remains unauthorized.

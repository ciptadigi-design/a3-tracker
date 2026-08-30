# Hostinger requirements and unknowns

Before M2.11K, verify SSH host/port/user/key authorization, subdomain/document root, PHP version, Composer/SSH, MySQL host/name/user/password, phpMyAdmin, Git deployment, cron cadence, symlink support, outbound HTTPS/mail, and backup retention. Record `memory_limit`, `max_execution_time`, upload/post limits, max input vars, process/database connection limits, storage/inode quota, jailed-shell behavior, and whether Node is available (not required at runtime).

Backups: Hostinger native backup plus independent logical SQL dump, release archive, and migration manifest; take an independent export before cutover mutations. Secrets stay in Hostinger environment/configuration: no SSH keys, `.env`, APP_KEY, passwords, tokens, or dumps in Git.

Scheduler is currently not required. If future scheduled work appears, use `php artisan schedule:run` via Hostinger cron. No persistent queue worker or WebSocket/realtime service is justified by the current repository; any async candidate is sync/cron/database-queue-with-cron, never Supervisor-dependent without explicit plan support.

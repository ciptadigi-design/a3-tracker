<?php
/*
 * M2.17.5.3: run this once, over HTTP, immediately after every atomic `current`
 * symlink swap on Hostinger.
 *
 * Root cause this exists for: PHP's opcache.revalidate_path defaults to Off
 * (PHP_INI_SYSTEM - not overridable from .htaccess, .user.ini, or ini_set(),
 * and this shared host does not expose a way to change it). With it Off,
 * OPcache permanently binds the *first* real path it ever resolves
 * "current/backend/..." to, for the lifetime of its shared-memory segment -
 * a plain symlink repoint alone does NOT make already-warmed workers see the
 * new release. Confirmed in Production: after swapping `current` to a new
 * release, /api/v1/version kept reporting the PREVIOUS release's git_sha
 * (proven via bootstrap/app.php resolving basePath() to the OLD release
 * directory under real web traffic) until this ran - CLI `php artisan` calls
 * do not help, because the web SAPI (LiteSpeed/LSPHP) is a completely
 * separate PHP install/opcache instance from the CLI binary used for
 * deployment commands.
 *
 * Usage (from an operator machine, after the symlink swap and before
 * declaring the deployment complete):
 *   scp -i <key> -P 65002 scripts/deployment/reset-opcache.php \
 *     <user>@<host>:<public_html>/reset-opcache-<random>.php
 *   curl -s https://<domain>/reset-opcache-<random>.php
 *   ssh ... "rm -f <public_html>/reset-opcache-<random>.php"
 *
 * Use a random/unguessable filename and delete it immediately after one use -
 * this must never be left reachable in public_html. It exposes no data; it
 * only reports whether the reset call itself succeeded.
 */

header('Content-Type: text/plain');

if (! function_exists('opcache_reset')) {
    http_response_code(500);
    echo "OPCACHE_NOT_AVAILABLE\n";
    exit;
}

echo opcache_reset() ? "OPCACHE_RESET_OK\n" : "OPCACHE_RESET_FAILED\n";

<?php
/**
 * Safely extract DB_* connection fields from a Laravel .env file and write a
 * MySQL "defaults extra file" (--defaults-extra-file=...) containing them.
 *
 * This exists because naive shell parsing of a quoted .env value — e.g.
 *   grep '^DB_PASSWORD=' .env | cut -d= -f2
 * — keeps the literal surrounding double quotes as part of the value when the
 * .env line is DB_PASSWORD="actual-password". mysqldump/mysql then receive a
 * corrupted credential, auth fails, and (if the caller does not check the
 * exit code before gzip) a valid-but-empty gzip stream is silently written
 * in place of a real backup. This is the exact root cause identified during
 * M2.15.2 and, retroactively, of the pre-existing 20-byte M2.13.1 backup.
 *
 * This script never echoes the parsed values to stdout/stderr. It writes
 * them only into the target defaults file, created with mode 0600.
 *
 * Usage:
 *   php mysql-defaults-from-env.php <path-to-.env> <path-to-write-defaults-file>
 * Exit codes:
 *   0  success, defaults file written
 *   10 usage error
 *   11 .env file unreadable
 *   12 a required DB_* key is missing from .env
 *   13 a required DB_* key resolved to an empty value
 *   14 could not create the defaults file securely
 */

function fail(int $code, string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($code);
}

$envPath = $argv[1] ?? null;
$outPath = $argv[2] ?? null;
if ($envPath === null || $outPath === null) {
    fail(10, 'usage: mysql-defaults-from-env.php <path-to-.env> <path-to-write-defaults-file>');
}
if (! is_readable($envPath)) {
    fail(11, 'cannot read env file (path withheld from output)');
}

/**
 * Minimal, dependency-free dotenv value decoder matching the subset of
 * dotenv quoting Laravel/Symfony Dotenv actually produces for this project's
 * .env files: KEY="value with \" \\ \$ escapes" or KEY=bareword.
 */
function decodeDotenvValue(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if ($raw[0] === '"' && str_ends_with($raw, '"') && strlen($raw) >= 2) {
        $inner = substr($raw, 1, -1);

        return preg_replace_callback('/\\\\(["\\\\$])/', static fn (array $m): string => $m[1], $inner) ?? $inner;
    }
    if ($raw[0] === "'" && str_ends_with($raw, "'") && strlen($raw) >= 2) {
        return substr($raw, 1, -1);
    }

    // Bare (unquoted) value: stop at the first unescaped # comment marker, trim trailing whitespace.
    $value = preg_split('/(?<!\\\\)#/', $raw, 2)[0];

    return rtrim($value);
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fail(11, 'failed reading env file');
}

$values = [];
foreach ($lines as $line) {
    $line = ltrim($line);
    if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
        continue;
    }
    [$key, $raw] = explode('=', $line, 2);
    $key = trim($key);
    $values[$key] = decodeDotenvValue($raw);
}

$required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
foreach ($required as $key) {
    if (! array_key_exists($key, $values)) {
        fail(12, "required key missing from env file: {$key}");
    }
    if ($key !== 'DB_PASSWORD' && $values[$key] === '') {
        fail(13, "required key resolved to an empty value: {$key}");
    }
}
if ($values['DB_PASSWORD'] === '') {
    fail(13, 'DB_PASSWORD resolved to an empty value — refusing to write an unauthenticated defaults file');
}

$escape = static fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

$content = "[client]\n";
$content .= 'host="'.$escape($values['DB_HOST'])."\"\n";
$content .= 'port='.((int) $values['DB_PORT'])."\n";
$content .= 'user="'.$escape($values['DB_USERNAME'])."\"\n";
$content .= 'password="'.$escape($values['DB_PASSWORD'])."\"\n";

$previousUmask = umask(0177);
$written = file_put_contents($outPath, $content);
umask($previousUmask);
if ($written === false) {
    fail(14, 'failed writing defaults file');
}
chmod($outPath, 0600);

// Defensive: verify no other process/user can read it before we report success.
$perms = fileperms($outPath) & 0777;
if ($perms !== 0600) {
    @unlink($outPath);
    fail(14, 'defaults file permissions could not be locked to 0600');
}

// DB_DATABASE is not a secret; emit it so callers can build a full mysqldump
// invocation (--defaults-extra-file=... "$DB_DATABASE") without parsing the
// .env file a second time via a separate, more fragile shell snippet.
echo 'DB_DATABASE='.$values['DB_DATABASE'].PHP_EOL;
exit(0);

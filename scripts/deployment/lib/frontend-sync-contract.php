<?php

// Read-only contract checks shared by preflight and post-sync verification.
// PHP + DOM are available on Hostinger; Node is intentionally not required.
declare(strict_types=1);

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function directory(string $path): string
{
    if ($path === '' || preg_match('/[\x00-\x1f]/', $path)) {
        fail('empty or control-character path');
    }
    // Strip trailing slash/dot aliases before testing the directory entry itself.
    $leaf = preg_replace('~(?:/\.)*/+$|(?:/\.)+$~', '', $path);
    if (is_link($leaf) || ! is_dir($path)) {
        fail('directory missing or symlink directory: '.$path);
    }
    $resolved = realpath($path);
    if ($resolved === false || $resolved === '/') {
        fail('filesystem root is forbidden');
    }

    return $resolved;
}

function artifact(string $root, bool $public): void
{
    foreach (['index.html', 'build-manifest.json'] as $name) {
        if (is_link("$root/$name") || ! is_file("$root/$name") || ! is_readable("$root/$name")) {
            fail("missing regular readable $name");
        }
    }
    if (is_link("$root/assets") || ! is_dir("$root/assets")) {
        fail('missing regular assets directory');
    }
    $infra = ['.htaccess', 'index.php', '.a3-active'];
    foreach (scandir($root) as $name) {
        if (! $public && in_array($name, $infra, true)) {
            fail('artifact collides with protected infrastructure: '.$name);
        }
        if (in_array($name, ['dist', 'src', 'backend', 'node_modules', '.git', '.env', 'package.json', 'package-lock.json'], true)) {
            fail('repository material or nested dist is not a frontend artifact: '.$name);
        }
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($entries as $entry) {
        if ($public && in_array($entry->getPathname(), array_map(fn ($name) => "$root/$name", $infra), true)) {
            continue;
        }
        if ($entry->isLink() || (! $entry->isFile() && ! $entry->isDir()) || ! $entry->isReadable()) {
            fail('artifact contains symlink, special, or unreadable entry: '.$entry->getPathname());
        }
    }
    $manifest = json_decode(file_get_contents("$root/build-manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['dataBackend'] ?? null) !== 'laravel' || ($manifest['apiBaseUrl'] ?? null) !== '/api/v1') {
        fail('manifest must declare dataBackend=laravel and apiBaseUrl=/api/v1');
    }
    $html = file_get_contents("$root/index.html");
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~/src/|/@vite/client|localhost|127\.0\.0\.1~i', rawurldecode($decoded))) {
        fail('index contains a source/development reference');
    }
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    if (! $dom->loadHTML($html, LIBXML_NONET)) {
        fail('index is not parseable HTML');
    }
    if ($dom->getElementsByTagName('base')->length > 0) {
        fail('base URL overrides are forbidden');
    }
    $entryFound = false;
    foreach ($dom->getElementsByTagName('*') as $element) {
        foreach (['src', 'href'] as $attribute) {
            if (! $element->hasAttribute($attribute)) {
                continue;
            }
            $url = $element->getAttribute($attribute);
            $script = $element->tagName === 'script' && $attribute === 'src';
            $style = $element->tagName === 'link' && $attribute === 'href'
                && preg_match('/(?:^|\s)(stylesheet|modulepreload)(?:\s|$)/i', $element->getAttribute('rel'));
            if (! $script && ! $style && ! preg_match('/\.(?:m?js|css)(?:[?#]|$)/i', rawurldecode($url))) {
                continue;
            }
            // Only canonical root-relative built URLs: no traversal, encoding,
            // backslashes, remote URLs, query/fragment aliases or empty segments.
            if (! preg_match('~^/assets/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:js|css)$~D', $url)
                || str_contains($url, '..')) {
                fail('non-canonical JS/CSS asset path: '.$url);
            }
            $path = realpath($root.$url);
            if ($path === false || ! str_starts_with($path, $root.'/') || ! is_file($path) || ! is_readable($path)) {
                fail('missing or escaping referenced asset: '.$url);
            }
            if ($script && strtolower($element->getAttribute('type')) === 'module'
                && preg_match('~^/assets/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+-[A-Za-z0-9_-]+\.js$~D', $url)) {
                $entryFound = true;
            }
        }
        if ($element->tagName === 'script' && ! $element->hasAttribute('src')) {
            fail('inline script is not part of the canonical Vite index');
        }
    }
    if (! $entryFound) {
        fail('index must reference at least one hashed /assets/ JavaScript module entry');
    }
    echo "FRONTEND_ARTIFACT_VERIFIED\n";
}

function invariants(string $root): void
{
    $stat = stat($root);
    $result = ['root' => array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']))];
    foreach (['.htaccess', 'index.php', '.a3-active'] as $name) {
        $path = "$root/$name";
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            fail('missing regular infrastructure file: '.$name);
        }
        $result[$name] = [array_intersect_key(lstat($path), array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'])), hash_file('sha256', $path)];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
}

try {
    $mode = $argv[1] ?? '';
    $root = directory($argv[2] ?? '');
    if ($mode === 'paths') {
        $destination = directory($argv[3] ?? '');
        if ($root === $destination || str_starts_with($root.'/', $destination.'/') || str_starts_with($destination.'/', $root.'/')) {
            fail('source and destination must be separate, non-nested directories');
        }
        echo "$root\n$destination\n";
    } elseif ($mode === 'artifact' || $mode === 'public') {
        artifact($root, $mode === 'public');
    } elseif ($mode === 'invariants') {
        invariants($root);
    } else {
        fail('unknown validation mode');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'SYNC_FAIL: '.$error->getMessage()."\n");
    exit(1);
}

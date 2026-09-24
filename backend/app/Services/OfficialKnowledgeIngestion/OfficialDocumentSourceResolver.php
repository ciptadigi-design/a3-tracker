<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Models\MaintenanceDocument;
use App\Services\DocumentStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class OfficialDocumentSourceResolver
{
    public function resolve(MaintenanceDocument $document, string $operatorPath, string $expectedSha256): ResolvedOfficialDocumentSource
    {
        if (! $document->exists || $document->status !== 'PUBLISHED' || ! $document->is_active) {
            throw new RuntimeException('The authoritative maintenance document must exist and be active/published.');
        }
        if (! is_string($document->storage_disk) || $document->storage_disk === '' || ! is_string($document->file_path) || $document->file_path === '') {
            throw new RuntimeException('The maintenance document has no authoritative stored file.');
        }
        $this->assertSafeRelativeStoragePath($document->file_path);
        if ((config("filesystems.disks.{$document->storage_disk}.driver")) !== 'local') {
            throw new RuntimeException('Full-manual ingestion requires a configured private local storage disk.');
        }
        if (strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('The authoritative stored file must use the PDF extension.');
        }
        if ($document->mime_type !== null && $document->mime_type !== 'application/pdf') {
            throw new RuntimeException('The authoritative stored file metadata is not application/pdf.');
        }

        $disk = Storage::disk($document->storage_disk);
        if (! $disk->exists($document->file_path)) {
            throw new RuntimeException('The authoritative stored PDF does not exist.');
        }
        $path = $disk->path($document->file_path);
        $canonical = realpath($path);
        $root = realpath($disk->path(''));
        if ($canonical === false || $root === false || ! is_file($canonical) || ! is_readable($canonical)) {
            throw new RuntimeException('The authoritative stored PDF is not a readable regular file.');
        }
        if (! $this->isWithin($canonical, $root) && ! $this->isWithinTrustedMaintenanceStorage($disk, $document->file_path, $canonical)) {
            throw new RuntimeException('The authoritative stored PDF resolves outside its storage root.');
        }
        $operatorCanonical = $operatorPath === '' ? false : realpath($operatorPath);
        if ($operatorCanonical === false || ! is_file($operatorCanonical) || $operatorCanonical !== $canonical) {
            throw new RuntimeException('--pdf must resolve exactly to the authoritative stored PDF.');
        }
        if ($document->file_size !== null && filesize($canonical) !== $document->file_size) {
            throw new RuntimeException('The authoritative stored PDF size does not match document metadata.');
        }

        $snapshot = tempnam(sys_get_temp_dir(), 'official-manual-');
        if (! is_string($snapshot) || ! copy($canonical, $snapshot) || ! chmod($snapshot, 0600)) {
            $this->safeCleanup($snapshot, $canonical);
            throw new RuntimeException('Unable to create a private source snapshot.');
        }
        $actual = hash_file('sha256', $snapshot);
        if (! is_string($actual) || ! hash_equals($expectedSha256, $actual)) {
            $this->safeCleanup($snapshot, $canonical);
            throw new RuntimeException('The authoritative source PDF SHA-256 does not match the reviewed contract.');
        }

        return new ResolvedOfficialDocumentSource(
            $canonical,
            $snapshot,
            $actual,
            $document->file_name ?: basename($document->file_path),
            $document->storage_disk,
            $document->file_path,
        );
    }

    private function assertSafeRelativeStoragePath(string $path): void
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException('The authoritative stored PDF path must be a safe relative disk path.');
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException('The authoritative stored PDF path must be a safe relative disk path.');
        }
    }

    private function isWithinTrustedMaintenanceStorage(FilesystemAdapter $disk, string $storagePath, string $canonicalFile): bool
    {
        $directory = DocumentStorageService::DIRECTORY;
        if (! str_starts_with($storagePath, $directory.'/')) {
            return false;
        }

        $authorizedRoot = realpath($disk->path($directory));

        return $authorizedRoot !== false
            && is_dir($authorizedRoot)
            && $this->isWithin($canonicalFile, $authorizedRoot);
    }

    private function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    public function revalidate(MaintenanceDocument $document, ResolvedOfficialDocumentSource $source, string $expectedSha256): void
    {
        if ($document->storage_disk !== $source->storageDisk || $document->file_path !== $source->storagePath) {
            throw new RuntimeException('The authoritative document storage identity changed after preview.');
        }
        $current = Storage::disk($document->storage_disk)->path($document->file_path);
        $canonical = realpath($current);
        if ($canonical === false || $canonical !== $source->canonicalPath) {
            throw new RuntimeException('The authoritative document path changed after preview.');
        }
        if ($document->file_size !== null && filesize($canonical) !== $document->file_size) {
            throw new RuntimeException('The authoritative document size changed after preview.');
        }
        $actual = hash_file('sha256', $source->canonicalPath);
        if (! is_string($actual) || ! hash_equals($expectedSha256, $actual)) {
            throw new RuntimeException('The authoritative source PDF changed after preview.');
        }
    }

    private function safeCleanup(string|false $candidate, string $authoritativePath): void
    {
        if (! is_string($candidate)) {
            return;
        }
        $candidateReal = realpath($candidate);
        $authoritativeReal = realpath($authoritativePath);
        if ($candidateReal !== false && $authoritativeReal !== false && $candidateReal !== $authoritativeReal && is_file($candidateReal)) {
            @unlink($candidateReal);
        }
    }
}

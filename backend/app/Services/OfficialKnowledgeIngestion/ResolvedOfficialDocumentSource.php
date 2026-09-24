<?php

namespace App\Services\OfficialKnowledgeIngestion;

final class ResolvedOfficialDocumentSource
{
    public function __construct(
        public readonly string $canonicalPath,
        public readonly string $snapshotPath,
        public readonly string $sha256,
        public readonly string $fileName,
        public readonly string $storageDisk,
        public readonly string $storagePath,
    ) {}

    public function cleanup(): void
    {
        $snapshot = realpath($this->snapshotPath);
        $source = realpath($this->canonicalPath);
        if ($snapshot !== false && $source !== false && $snapshot !== $source && is_file($snapshot)) {
            @unlink($snapshot);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}

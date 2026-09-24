<?php

namespace Tests\Feature;

use App\Models\MaintenanceDocument;
use App\Services\DocumentStorageService;
use App\Services\OfficialKnowledgeIngestion\OfficialDocumentSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OfficialDocumentSourceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_an_ordinary_file_physically_beneath_the_disk_root(): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $path = $root.'/ordinary.pdf';
        file_put_contents($path, 'ordinary authoritative bytes');
        $document = $this->document($disk, 'ordinary.pdf', $path);

        $source = $this->resolver()->resolve($document, $path, hash_file('sha256', $path));

        try {
            $this->assertSame(realpath($path), $source->canonicalPath);
        } finally {
            $source->cleanup();
            File::deleteDirectory($base);
        }
    }

    public function test_accepts_the_production_shape_with_a_trusted_maintenance_directory_symlink(): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $shared = $base.'/shared-maintenance-documents';
        mkdir($shared, 0700);
        symlink($shared, $root.'/'.DocumentStorageService::DIRECTORY);
        $path = $shared.'/authoritative.pdf';
        file_put_contents($path, 'shared authoritative bytes');
        $before = $this->identity($path);
        $document = $this->document($disk, DocumentStorageService::DIRECTORY.'/authoritative.pdf', $path);

        $source = $this->resolver()->resolve($document, $root.'/'.DocumentStorageService::DIRECTORY.'/authoritative.pdf', hash_file('sha256', $path));

        $this->assertSame(realpath($path), $source->canonicalPath);
        $this->assertNotSame($source->canonicalPath, $source->snapshotPath);
        $source->cleanup();
        $this->assertSame($before, $this->identity($path));
        File::deleteDirectory($base);
    }

    #[DataProvider('unsafeStoredPathProvider')]
    public function test_rejects_traversal_and_absolute_stored_paths(string $storedPath): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $outside = $base.'/outside.pdf';
        file_put_contents($outside, 'outside bytes');
        $document = $this->document($disk, $storedPath, $outside);

        try {
            $this->resolver()->resolve($document, $outside, hash_file('sha256', $outside));
            $this->fail('An unsafe stored path was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('safe relative disk path', $exception->getMessage());
        } finally {
            File::deleteDirectory($base);
        }
    }

    public static function unsafeStoredPathProvider(): array
    {
        return [
            'parent traversal' => ['../outside.pdf'],
            'nested traversal' => ['safe/../../outside.pdf'],
            'absolute Unix path' => ['/tmp/outside.pdf'],
            'absolute Windows path' => ['C:/outside.pdf'],
            'Windows traversal' => ['..\\outside.pdf'],
        ];
    }

    public function test_rejects_an_unrelated_sibling_symlink_escape(): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $outside = $base.'/outside.pdf';
        file_put_contents($outside, 'outside bytes');
        symlink($outside, $root.'/unrelated.pdf');
        $document = $this->document($disk, 'unrelated.pdf', $outside);

        $this->assertResolverRejectsOutsideAuthority($document, $root.'/unrelated.pdf', $outside);
        File::deleteDirectory($base);
    }

    public function test_rejects_a_nested_symlink_escape_from_the_approved_maintenance_authority(): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $maintenance = $root.'/'.DocumentStorageService::DIRECTORY;
        $outside = $base.'/outside';
        mkdir($maintenance, 0700);
        mkdir($outside, 0700);
        file_put_contents($outside.'/escaped.pdf', 'escaped bytes');
        symlink($outside, $maintenance.'/malicious');
        $document = $this->document($disk, DocumentStorageService::DIRECTORY.'/malicious/escaped.pdf', $outside.'/escaped.pdf');

        $this->assertResolverRejectsOutsideAuthority($document, $maintenance.'/malicious/escaped.pdf', $outside.'/escaped.pdf');
        File::deleteDirectory($base);
    }

    public function test_rejects_missing_files_and_directories(): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $missing = $root.'/missing.pdf';
        $document = $this->document($disk, 'missing.pdf', $missing, 0);
        try {
            $this->resolver()->resolve($document, $missing, hash('sha256', ''));
            $this->fail('A missing file was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not exist', $exception->getMessage());
        }

        $directory = $root.'/directory.pdf';
        mkdir($directory, 0700);
        $document->update(['file_path' => 'directory.pdf']);
        try {
            $this->resolver()->resolve($document->fresh(), $directory, hash('sha256', ''));
            $this->fail('A directory was accepted as a PDF.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('readable regular file', $exception->getMessage());
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_rejects_wrong_hash_and_wrong_recorded_size(): void
    {
        [$base, $root, $disk] = $this->filesystem();
        $path = $root.'/identity.pdf';
        file_put_contents($path, 'identity bytes');
        $document = $this->document($disk, 'identity.pdf', $path);

        try {
            $this->resolver()->resolve($document, $path, str_repeat('0', 64));
            $this->fail('A wrong SHA was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SHA-256 does not match', $exception->getMessage());
        }

        $document->update(['file_size' => filesize($path) + 1]);
        try {
            $this->resolver()->resolve($document->fresh(), $path, hash_file('sha256', $path));
            $this->fail('A wrong recorded size was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('size does not match', $exception->getMessage());
        } finally {
            File::deleteDirectory($base);
        }
    }

    private function assertResolverRejectsOutsideAuthority(MaintenanceDocument $document, string $operatorPath, string $sourcePath): void
    {
        try {
            $this->resolver()->resolve($document, $operatorPath, hash_file('sha256', $sourcePath));
            $this->fail('A symlink escape outside the authorized storage was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outside its storage root', $exception->getMessage());
        }
    }

    /** @return array{string, string, string} */
    private function filesystem(): array
    {
        $base = sys_get_temp_dir().'/official-resolver-'.bin2hex(random_bytes(8));
        $root = $base.'/disk';
        mkdir($root, 0700, true);
        $disk = 'r_'.bin2hex(random_bytes(6));
        config(["filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => $root, 'throw' => true]]);
        Storage::forgetDisk($disk);

        return [$base, $root, $disk];
    }

    private function document(string $disk, string $storedPath, string $sourcePath, ?int $size = null): MaintenanceDocument
    {
        return MaintenanceDocument::create([
            'title' => 'Resolver security fixture',
            'document_type' => 'SERVICE_MANUAL',
            'file_reference' => $storedPath,
            'file_path' => $storedPath,
            'file_name' => basename($storedPath),
            'file_size' => $size ?? (is_file($sourcePath) ? filesize($sourcePath) : null),
            'mime_type' => 'application/pdf',
            'storage_disk' => $disk,
            'status' => 'PUBLISHED',
            'is_active' => true,
        ]);
    }

    private function resolver(): OfficialDocumentSourceResolver
    {
        return $this->app->make(OfficialDocumentSourceResolver::class);
    }

    /** @return array{size: int, inode: int, mtime: int, sha256: string} */
    private function identity(string $path): array
    {
        return [
            'size' => filesize($path),
            'inode' => fileinode($path),
            'mtime' => filemtime($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\MaintenanceDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF storage for the machine document repository (V1.4). Private disk only -
 * `local` (storage/app/private, see config/filesystems.php) - never `public`, since
 * a document must only be reachable through the authenticated, tenant-scoped
 * download endpoint (MaintenanceDocumentController::download()), not a direct
 * public URL. scripts/deployment/link-shared-storage.sh symlinks this directory
 * into shared/ so uploaded files survive the next release's fresh `git clone`.
 *
 * Filenames are never derived from user input: the stored path is always
 * "<document id>.pdf" - the original client filename is kept only as display
 * metadata (file_name), never as part of the filesystem path.
 */
class DocumentStorageService
{
    public const DISK = 'local';

    public const DIRECTORY = 'maintenance-documents';

    // No prior upload convention existed anywhere in this app (V1.2/V1.3 kept
    // documents reference-metadata-only). Production's PHP upload_max_filesize/
    // post_max_size (2048M) impose no real ceiling, so 50MB is a deliberate,
    // documented application-level choice sized for scanned service manuals.
    public const MAX_FILE_SIZE_BYTES = 50 * 1024 * 1024;

    private function path(MaintenanceDocument $document): string
    {
        return self::DIRECTORY.'/'.$document->id.'.pdf';
    }

    private function validate(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be read.']);
        }
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['file' => 'Only PDF files are accepted.']);
        }
        if ($file->getMimeType() !== 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'Only PDF files are accepted.']);
        }
        if ($file->getSize() === false || $file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw ValidationException::withMessages(['file' => 'File exceeds the maximum allowed size of '.(self::MAX_FILE_SIZE_BYTES / 1024 / 1024).'MB.']);
        }
    }

    /**
     * Stores (or replaces) the PDF for a document and returns the metadata columns
     * to persist on the model. Never leaves two physical files behind - a prior
     * stored file at this document's path is overwritten atomically by storeAs().
     */
    public function store(MaintenanceDocument $document, UploadedFile $file): array
    {
        $this->validate($file);
        $path = $file->storeAs(self::DIRECTORY, $document->id.'.pdf', self::DISK);

        return [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => 'application/pdf',
            'storage_disk' => self::DISK,
            'uploaded_at' => now(),
        ];
    }

    public function hasStoredFile(MaintenanceDocument $document): bool
    {
        return $document->storage_disk !== null && $document->file_path !== null;
    }

    public function deleteFile(MaintenanceDocument $document): void
    {
        if ($this->hasStoredFile($document) && Storage::disk($document->storage_disk)->exists($document->file_path)) {
            Storage::disk($document->storage_disk)->delete($document->file_path);
        }
    }

    /** Metadata reset for a document's file-related columns, keeping every other field intact. */
    public function clearedMetadata(): array
    {
        return ['file_path' => null, 'file_name' => null, 'file_size' => null, 'mime_type' => null, 'storage_disk' => null, 'uploaded_at' => null];
    }

    public function download(MaintenanceDocument $document, bool $inline = false): StreamedResponse
    {
        abort_unless($this->hasStoredFile($document) && Storage::disk($document->storage_disk)->exists($document->file_path), 404);
        $disk = Storage::disk($document->storage_disk);
        $filename = $document->file_name ?: ($document->id.'.pdf');

        return $inline ? $disk->response($document->file_path, $filename) : $disk->download($document->file_path, $filename);
    }
}

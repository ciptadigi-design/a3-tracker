<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only. file_path/file_name/file_size/mime_type already exist (V1.2) and
        // are reused as-is for real uploads - only storage_disk (which disk/driver the
        // file lives on, so a document can distinguish "an internally stored upload" from
        // "an external file_path reference/URL", the V1.2 behavior) and uploaded_at are new.
        Schema::table('maintenance_documents', function (Blueprint $t) {
            $t->string('storage_disk', 20)->nullable()->after('mime_type');
            $t->timestampTz('uploaded_at')->nullable()->after('storage_disk');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_documents', function (Blueprint $t) {
            $t->dropColumn(['storage_disk', 'uploaded_at']);
        });
    }
};

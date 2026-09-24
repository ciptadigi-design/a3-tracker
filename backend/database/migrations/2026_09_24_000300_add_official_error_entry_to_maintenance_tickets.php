<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->uuid('official_error_entry_id')->nullable()->after('error_code_id');
            $table->index('official_error_entry_id', 'mt_official_error_entry_idx');
            $table->foreign('official_error_entry_id', 'mt_official_error_entry_fk')
                ->references('id')->on('maintenance_official_error_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->dropForeign('mt_official_error_entry_fk');
            $table->dropIndex('mt_official_error_entry_idx');
            $table->dropColumn('official_error_entry_id');
        });
    }
};

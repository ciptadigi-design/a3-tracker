<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2.17.5.2: the Add/Edit Component form already had a fully-built Manufacturer
 * selector ("Manufacturer describes the definition; model assignment is configured
 * separately.") and already looked up `component.manufacturer_id` to display it on
 * saved components, but `component_catalogs` never actually had this column - every
 * selection was silently discarded on save, on top of the dropdown itself being
 * empty (a separate frontend bug: loadComponentFoundation() never fetched
 * manufacturers at all). Nullable and optional throughout: "Any manufacturer" is a
 * real NULL, not a required assignment, and this metadata field has no relationship
 * to machine-model compatibility (that remains exclusively configured through Model
 * Profiles/slots).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_catalogs', function (Blueprint $table): void {
            $table->uuid('manufacturer_id')->nullable()->after('account_id');
            $table->foreign('manufacturer_id')->references('id')->on('manufacturers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('component_catalogs', function (Blueprint $table): void {
            $table->dropForeign(['manufacturer_id']);
            $table->dropColumn('manufacturer_id');
        });
    }
};

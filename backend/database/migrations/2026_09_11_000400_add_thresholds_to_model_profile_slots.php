<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2.17.5.2 Part B: "Edit Profile" (ProfileDialog.jsx) has always read and written
 * healthy/watch/warning/critical_threshold_percent and adaptive_enabled against a
 * `profile` prop that is really a flattened ModelProfileSlot row - but
 * model_profile_slots never had these columns. Every save appeared to succeed
 * (the extra keys were just silently dropped by validate()'s whitelist), then
 * reopening the dialog re-derived defaults from `undefined`, reading back as
 * blank/unchecked. The real runtime threshold source-of-truth for health
 * calculation is machine_components (see 2026_08_31_001000), which sync() does
 * NOT copy from the slot - this migration makes the Model Profile Slot the
 * template these values are authored against, and a follow-up service change
 * makes sync() snapshot them onto new machine_components at assignment time.
 *
 * adaptive_enabled is added so the checkbox round-trips honestly (it is a real,
 * persisted, hydrated boolean once this ships) but no lifecycle/health
 * calculation anywhere currently reads it - it is UI-only until such logic
 * exists. That capability gap is intentional and must not be papered over.
 *
 * Defaults (30/15/5/0, adaptive true) match machine_components' existing
 * column defaults and ProfileDialog's own new-profile defaults, so slots
 * created outside this dialog (e.g. storeProfile's quick-assign path) still
 * hydrate to sensible, already-familiar values rather than blanks.
 *
 * `notes` is added for the same reason: ProfileDialog has always collected a
 * Notes textarea, but neither the old slotPayload() mapping nor this table
 * ever had anywhere to put it - it was decorative. This is a template-level
 * note about the slot itself, distinct from machine_components.notes (a
 * machine-specific note on one manually-tracked instance), so it is
 * deliberately NOT copied into machine_components by sync()/reconcileManual().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_profile_slots', function (Blueprint $table): void {
            $table->decimal('healthy_threshold_percent', 5, 2)->unsigned()->default(30)->after('baseline_expected_clicks');
            $table->decimal('watch_threshold_percent', 5, 2)->unsigned()->default(15)->after('healthy_threshold_percent');
            $table->decimal('warning_threshold_percent', 5, 2)->unsigned()->default(5)->after('watch_threshold_percent');
            $table->decimal('critical_threshold_percent', 5, 2)->unsigned()->default(0)->after('warning_threshold_percent');
            $table->boolean('adaptive_enabled')->default(true)->after('critical_threshold_percent');
            $table->text('notes')->nullable()->after('adaptive_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('model_profile_slots', function (Blueprint $table): void {
            $table->dropColumn(['healthy_threshold_percent', 'watch_threshold_percent', 'warning_threshold_percent', 'critical_threshold_percent', 'adaptive_enabled', 'notes']);
        });
    }
};

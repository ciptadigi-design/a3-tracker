<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.8.1 - Solution Variant Publishing. Purely additive: one nullable column on the existing
 * maintenance_error_solutions table, no new table.
 *
 * Real-document motivation (read-only Production investigation of C-1127): the manual gives the
 * SAME error code two materially different technician procedures depending on which optional
 * accessory hardware is installed (e.g. "PK-512/513" vs "PK-522"), and this pattern recurs across
 * at least 20 other codes in the same document, not just C-1127. maintenance_error_solutions was
 * already a multi-row, ordered table (unique on machine_error_code_id + step_number - any number
 * of rows can already belong to one code), so the gap was never row-level: a solution row simply
 * had no way to say WHICH hardware/accessory/model context it applies to.
 *
 * applicability_label is that label: a short, free-text, reviewer-authored string such as
 * "PK-512/513". NULL means "generally applicable" - the existing, unlabeled behavior every prior
 * publish (V1.3 legacy per-candidate, V1.8 per-group) already produces, completely unchanged.
 * step_number keeps its existing meaning (an ordered solution-entry index, not a literal
 * step-in-procedure counter - each row already holds one complete narrative) and its existing
 * uniqueness constraint is untouched: two variant rows for one code simply get consecutive
 * step_number values, exactly as any two solution rows for one code already do today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_error_solutions', function (Blueprint $t) {
            $t->string('applicability_label', 160)->nullable()->after('step_number');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_error_solutions', function (Blueprint $t) {
            $t->dropColumn('applicability_label');
        });
    }
};

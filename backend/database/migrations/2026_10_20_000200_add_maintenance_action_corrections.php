<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A correction is a new append-only row pointing at the original activity; the
        // original is never touched. Additive and nullable, so no backfill is needed.
        Schema::table('maintenance_actions', function (Blueprint $table) {
            $table->uuid('corrects_action_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreign('corrects_action_id', 'maintenance_actions_corrects_fk')
                ->references('id')->on('maintenance_actions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_actions', function (Blueprint $table) {
            Schema::getConnection()->getDriverName() === 'sqlite'
                ? $table->dropForeign(['corrects_action_id'])
                : $table->dropForeign('maintenance_actions_corrects_fk');
            $table->dropColumn(['corrects_action_id', 'correction_reason']);
        });
    }
};

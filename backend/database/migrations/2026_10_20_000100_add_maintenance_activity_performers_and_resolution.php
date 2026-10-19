<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only: every column is nullable so historical tickets/actions stay
        // valid without a backfill. maintenance_actions is the activity log
        // (append-only at the model layer); operator performers reference the same
        // operational_people directory Input Click uses, never a new PIC table.
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->uuid('internal_pic_person_id')->nullable();
            $table->string('internal_pic_name', 160)->nullable();
            $table->string('external_technician_name', 120)->nullable();
            $table->string('external_vendor_name', 120)->nullable();
            $table->text('resolution_cause')->nullable();
            $table->text('resolution_action')->nullable();
            $table->string('resolution_test_result', 24)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->foreign(['internal_pic_person_id', 'account_id'], 'maintenance_tickets_pic_person_fk')
                ->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
            $table->foreign('resolved_by', 'maintenance_tickets_resolved_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('maintenance_actions', function (Blueprint $table) {
            $table->uuid('operator_person_id')->nullable();
            $table->string('operator_name', 160)->nullable();
            $table->string('external_technician_name', 120)->nullable();
            $table->string('vendor_name', 120)->nullable();
            $table->text('findings')->nullable();
            $table->foreign(['operator_person_id', 'account_id'], 'maintenance_actions_person_fk')
                ->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('maintenance_actions', function (Blueprint $table) use ($sqlite) {
            $sqlite ? $table->dropForeign(['operator_person_id', 'account_id']) : $table->dropForeign('maintenance_actions_person_fk');
            $table->dropColumn(['operator_person_id', 'operator_name', 'external_technician_name', 'vendor_name', 'findings']);
        });
        Schema::table('maintenance_tickets', function (Blueprint $table) use ($sqlite) {
            $sqlite ? $table->dropForeign(['internal_pic_person_id', 'account_id']) : $table->dropForeign('maintenance_tickets_pic_person_fk');
            $sqlite ? $table->dropForeign(['resolved_by']) : $table->dropForeign('maintenance_tickets_resolved_by_fk');
            $table->dropColumn(['internal_pic_person_id', 'internal_pic_name', 'external_technician_name', 'external_vendor_name', 'resolution_cause', 'resolution_action', 'resolution_test_result', 'resolution_notes', 'resolved_by']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downtime_events', function (Blueprint $table) {
            $table->unique(['id', 'account_id', 'branch_id', 'machine_id'], 'downtime_events_ticket_scope_uq');
        });

        Schema::table('maintenance_tickets', function (Blueprint $table) {
            // Nullable preserves every historical/manual ticket. The unique key
            // makes one downtime event the source of at most one ticket, while the
            // composite FK prevents cross-account links at the database boundary.
            $table->uuid('downtime_event_id')->nullable()->after('client_request_id');
            $table->unique('downtime_event_id', 'maintenance_tickets_downtime_event_uq');
            $table->foreign(['downtime_event_id', 'account_id', 'branch_id', 'machine_id'], 'maintenance_tickets_downtime_event_fk')
                ->references(['id', 'account_id', 'branch_id', 'machine_id'])
                ->on('downtime_events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['downtime_event_id', 'account_id', 'branch_id', 'machine_id']);
            } else {
                $table->dropForeign('maintenance_tickets_downtime_event_fk');
            }
            $table->dropUnique('maintenance_tickets_downtime_event_uq');
            $table->dropColumn('downtime_event_id');
        });
        Schema::table('downtime_events', function (Blueprint $table) {
            $table->dropUnique('downtime_events_ticket_scope_uq');
        });
    }
};

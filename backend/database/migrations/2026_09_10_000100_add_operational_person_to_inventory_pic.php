<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->uuid('entered_by')->nullable()->after('reason');
            $t->uuid('operational_person_id')->nullable()->after('entered_by');
            $t->string('operational_person_name_snapshot', 160)->nullable()->after('operational_person_id');
            $t->foreign('entered_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign(['operational_person_id', 'account_id'])->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
        });
        Schema::table('component_replacements', function (Blueprint $t) {
            $t->uuid('entered_by')->nullable()->after('notes');
            $t->uuid('performed_by_person_id')->nullable()->after('entered_by');
            $t->string('performed_by_name_snapshot', 160)->nullable()->after('performed_by_person_id');
            $t->foreign('entered_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign(['performed_by_person_id', 'account_id'])->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropForeign(['operational_person_id', 'account_id']);
            $t->dropForeign(['entered_by']);
            $t->dropColumn(['entered_by', 'operational_person_id', 'operational_person_name_snapshot']);
        });
        Schema::table('component_replacements', function (Blueprint $t) {
            $t->dropForeign(['performed_by_person_id', 'account_id']);
            $t->dropForeign(['entered_by']);
            $t->dropColumn(['entered_by', 'performed_by_person_id', 'performed_by_name_snapshot']);
        });
    }
};

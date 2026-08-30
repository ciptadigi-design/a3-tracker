<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counter_readings', function (Blueprint $t) {
            $t->uuid('corrects_reading_id')->nullable()->after('previous_reading_id');
            $t->string('correction_reason', 500)->nullable()->after('notes');
            $t->unique('corrects_reading_id', 'counter_corrects_unique');
            $t->foreign('corrects_reading_id')->references('id')->on('counter_readings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('counter_readings', function (Blueprint $t) {
            $t->dropForeign(['corrects_reading_id']);
            $t->dropUnique('counter_corrects_unique');
            $t->dropColumn(['corrects_reading_id', 'correction_reason']);
        });
    }
};

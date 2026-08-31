<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_person_branches', function (Blueprint $table) {
            $table->boolean('can_record_counter')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('operational_person_branches', fn (Blueprint $table) => $table->dropColumn('can_record_counter'));
    }
};

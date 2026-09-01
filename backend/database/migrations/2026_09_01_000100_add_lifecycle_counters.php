<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_lifecycles', function (Blueprint $table): void {
            $table->decimal('installed_counter', 20, 4)->nullable()->after('machine_component_id');
            $table->decimal('removed_counter', 20, 4)->nullable()->after('installed_counter');
            $table->decimal('actual_usage', 20, 4)->nullable()->after('removed_counter');
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE component_lifecycles ADD CONSTRAINT component_lifecycle_counter_order_ck CHECK (removed_counter IS NULL OR installed_counter IS NULL OR removed_counter >= installed_counter)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') DB::statement('ALTER TABLE component_lifecycles DROP CONSTRAINT component_lifecycle_counter_order_ck');
        Schema::table('component_lifecycles', fn (Blueprint $table) => $table->dropColumn(['installed_counter', 'removed_counter', 'actual_usage']));
    }
};

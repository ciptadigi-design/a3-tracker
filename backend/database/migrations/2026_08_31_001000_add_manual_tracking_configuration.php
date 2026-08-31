<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_components', function (Blueprint $t) {
            $t->string('tracking_method', 32)->default('counter_based')->after('source_type');
            $t->unsignedBigInteger('baseline_expected_clicks')->nullable()->after('tracking_method');
            $t->decimal('healthy_threshold_percent', 5, 2)->unsigned()->default(30)->after('baseline_expected_clicks');
            $t->decimal('watch_threshold_percent', 5, 2)->unsigned()->default(15)->after('healthy_threshold_percent');
            $t->decimal('warning_threshold_percent', 5, 2)->unsigned()->default(5)->after('watch_threshold_percent');
            $t->decimal('critical_threshold_percent', 5, 2)->unsigned()->default(0)->after('warning_threshold_percent');
            $t->text('notes')->nullable()->after('critical_threshold_percent');
            $t->index(['machine_id', 'tracking_method'], 'mc_tracking_method_idx');
        });
    }

    public function down(): void
    {
        Schema::table('machine_components', function (Blueprint $t) {
            $t->dropIndex('mc_tracking_method_idx');
            $t->dropColumn(['tracking_method', 'baseline_expected_clicks', 'healthy_threshold_percent', 'watch_threshold_percent', 'warning_threshold_percent', 'critical_threshold_percent', 'notes']);
        });
    }
};

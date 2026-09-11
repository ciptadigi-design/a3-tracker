<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2.17.5.5: component_lifecycles previously had no persisted expected-life
// baseline of its own - the frontend fell through to the machine component's
// current (mutable) baseline_expected_clicks for both active AND historical
// closed lifecycles. That meant editing a Model Profile later could silently
// change what a lifecycle installed months ago appears to have expected.
//
// This column is a point-in-time snapshot taken once, at lifecycle creation
// (see ComponentConfigurationService::resolveEffectiveBaseline(), used by
// both ComponentConfigurationService::initialize() and
// ReplaceMachineComponent::execute()), and never rewritten afterwards.
//
// Historical rows created before this migration have no factual snapshot
// evidence available and are intentionally left null - the frontend
// (laravelComponentProjection.js) falls back to the machine component's
// current baseline_expected_clicks for those, matching prior behavior. No
// value is fabricated for them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_lifecycles', function (Blueprint $table): void {
            $table->unsignedBigInteger('baseline_expected_clicks_snapshot')->nullable()->after('actual_usage');
        });
    }

    public function down(): void
    {
        Schema::table('component_lifecycles', fn (Blueprint $table) => $table->dropColumn('baseline_expected_clicks_snapshot'));
    }
};

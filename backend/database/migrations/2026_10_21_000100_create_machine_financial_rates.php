<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configured ESTIMATES used only by the Owner "Dampak Finansial" view. Hourly
        // rates cannot be derived from machine_operating_costs (period amounts) or
        // machine_selling_prices (per click) without inventing throughput, so the owner
        // enters them. Whole Rupiah; NULL = not configured (never treated as 0). Additive:
        // no existing accounting table is touched.
        Schema::create('machine_financial_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('machine_id');
            $t->unsignedBigInteger('operating_cost_per_hour')->nullable();
            $t->unsignedBigInteger('estimated_revenue_per_hour')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->unique('machine_id');
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_financial_rates');
    }
};

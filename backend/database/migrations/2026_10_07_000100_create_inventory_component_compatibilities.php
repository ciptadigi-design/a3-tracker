<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_component_compatibilities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->nullable();
            $t->uuid('inventory_item_id');
            $t->uuid('component_id');
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['inventory_item_id', 'component_id'], 'inv_component_compat_pair_uq');
            $t->foreign('inventory_item_id')->references('id')->on('inventory_items')->restrictOnDelete();
            $t->foreign('component_id')->references('id')->on('component_catalogs')->restrictOnDelete();
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->index(['component_id', 'is_active'], 'inv_component_compat_component_idx');
            $t->index(['inventory_item_id', 'is_active'], 'inv_component_compat_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_component_compatibilities');
    }
};

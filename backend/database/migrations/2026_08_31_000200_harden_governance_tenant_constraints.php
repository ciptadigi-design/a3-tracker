<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_memberships', function (Blueprint $table) {
            $table->unique(['id', 'account_id'], 'memberships_id_account_unique');
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['id', 'account_id'], 'branches_id_account_unique');
        });
        Schema::table('account_membership_branches', function (Blueprint $table) {
            $table->dropForeign(['membership_id']);
            $table->dropForeign(['branch_id']);
            $table->foreign(['membership_id', 'account_id'], 'assignments_membership_account_fk')
                ->references(['id', 'account_id'])->on('account_memberships')->cascadeOnDelete();
            $table->foreign(['branch_id', 'account_id'], 'assignments_branch_account_fk')
                ->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_membership_branches', function (Blueprint $table) {
            $table->dropForeign('assignments_membership_account_fk');
            $table->dropForeign('assignments_branch_account_fk');
            $table->foreign('membership_id')->references('id')->on('account_memberships')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
        Schema::table('branches', fn (Blueprint $table) => $table->dropUnique('branches_id_account_unique'));
        Schema::table('account_memberships', fn (Blueprint $table) => $table->dropUnique('memberships_id_account_unique'));
    }
};

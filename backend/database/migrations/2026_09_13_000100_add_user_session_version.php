<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('session_version')->default(0);
        });
    }

    public function down(): void
    {
        // Roll back application code first; dropping this field removes revocation enforcement.
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_version'));
    }
};

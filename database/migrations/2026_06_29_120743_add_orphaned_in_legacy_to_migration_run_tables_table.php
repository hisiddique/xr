<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('migration_run_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('orphaned_in_legacy')->default(0)->after('failed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migration_run_tables', function (Blueprint $table) {
            $table->dropColumn('orphaned_in_legacy');
        });
    }
};

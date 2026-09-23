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
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('legacy_confirmed_paid')->default(false)->after('is_settled');
            $table->string('legacy_confirmed_paid_batch')->nullable()->after('legacy_confirmed_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['legacy_confirmed_paid', 'legacy_confirmed_paid_batch']);
        });
    }
};

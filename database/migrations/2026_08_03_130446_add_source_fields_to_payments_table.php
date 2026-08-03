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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source_type')->default('cash')->after('payment_method_id')->index();
            $table->string('payment_reference')->nullable()->after('reference');
            $table->boolean('is_exhausted')->default(false)->after('amount');
            $table->dropForeign(['payment_method_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->change();
            $table->foreign('payment_method_id')->references('id')->on('lookup_payment_methods');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable(false)->change();
            $table->foreign('payment_method_id')->references('id')->on('lookup_payment_methods');
            $table->dropColumn(['source_type', 'payment_reference', 'is_exhausted']);
        });
    }
};

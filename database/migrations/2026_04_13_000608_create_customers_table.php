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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('reference', 50)->unique()->nullable();
            $table->foreignId('title_id')->nullable()->constrained('lookup_titles')->nullOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('town', 100)->nullable();
            $table->string('post_code', 20)->nullable();
            $table->string('email_1')->nullable();
            $table->decimal('trade_discount', 5, 2)->default(0.00);
            $table->foreignId('credit_term_id')->nullable()->constrained('lookup_credit_terms')->nullOnDelete();
            $table->foreignId('credit_limit_id')->nullable()->constrained('lookup_credit_limits')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

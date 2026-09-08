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
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_group_members', function (Blueprint $table) {
            $table->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_group_id', 'customer_id']);
        });

        Schema::create('supplier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_group_members', function (Blueprint $table) {
            $table->foreignId('supplier_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->primary(['supplier_group_id', 'supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_group_members');
        Schema::dropIfExists('supplier_groups');
        Schema::dropIfExists('customer_group_members');
        Schema::dropIfExists('customer_groups');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('fee_payment_id')->constrained('fee_payments')->cascadeOnDelete();
            $table->foreignId('fee_student_allocation_id')->nullable()->constrained('fee_student_allocations')->nullOnDelete();
            $table->foreignId('fee_type_id')->nullable()->constrained('fee_types')->nullOnDelete();
            $table->foreignId('fee_discount_id')->nullable()->constrained('fee_discounts')->nullOnDelete();
            $table->string('label')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('discount_granted_by')->nullable()->index();
            $table->string('discount_reason')->nullable();
            $table->decimal('fine_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('fine_charged_by')->nullable()->index();
            $table->string('fine_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('school_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payment_details');
    }
};

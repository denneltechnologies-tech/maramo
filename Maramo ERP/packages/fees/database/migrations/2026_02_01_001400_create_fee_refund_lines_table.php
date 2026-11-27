<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_refund_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('fee_refund_id')->constrained('fee_refunds')->cascadeOnDelete();
            $table->foreignId('fee_payment_detail_id')->nullable()->constrained('fee_payment_details')->nullOnDelete();
            $table->foreignId('fee_student_allocation_id')->nullable()->constrained('fee_student_allocations')->nullOnDelete();
            $table->foreignId('fee_type_id')->nullable()->constrained('fee_types')->nullOnDelete();
            $table->string('label')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->boolean('restore_dues')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('school_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_refund_lines');
    }
};

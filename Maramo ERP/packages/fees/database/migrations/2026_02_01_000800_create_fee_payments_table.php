<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->string('receipt_no');
            $table->unsignedInteger('receipt_seq')->default(0);
            $table->date('payment_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('fine_amount', 15, 2)->default(0);
            // Manual receipts only: cash | cheque | dd | bank_transfer | upi | card (recorded by staff; no gateway).
            $table->string('mode', 20)->default('cash');
            $table->string('reference_no')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('collected_by')->nullable()->index();
            $table->string('status', 20)->default('completed'); // completed | reverted
            $table->timestamp('reverted_at')->nullable();
            $table->unsignedBigInteger('reverted_by')->nullable()->index();
            $table->string('revert_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'receipt_no']);
            $table->unique(['branch_id', 'receipt_seq'], 'fee_payments_branch_seq_unique');
            $table->index(['status', 'payment_date'], 'fee_payments_status_date_idx');
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
    }
};

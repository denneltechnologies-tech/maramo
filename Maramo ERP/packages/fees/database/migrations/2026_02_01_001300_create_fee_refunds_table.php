<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->foreignId('fee_payment_id')->constrained('fee_payments')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason_code', 40);
            $table->text('reason_detail')->nullable();
            $table->string('mode', 20)->default('original');
            $table->string('original_mode', 20)->nullable();
            $table->string('reference_no')->nullable();
            $table->boolean('restore_dues')->default(true);
            $table->string('status', 20)->default('requested');
            $table->unsignedTinyInteger('required_approvals')->default(0);
            $table->unsignedTinyInteger('approvals_received')->default(0);
            $table->decimal('threshold_at_request', 15, 2)->default(0);
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable()->index();
            $table->timestamp('issued_at')->nullable();
            $table->string('credit_note_no')->nullable();
            $table->unsignedInteger('credit_note_seq')->nullable();
            $table->unsignedBigInteger('student_credit_id')->nullable()->index();
            $table->string('payout_status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->unique(['branch_id', 'credit_note_seq'], 'fee_refunds_credit_note_seq_unique');
            $table->unique(['branch_id', 'credit_note_no'], 'fee_refunds_credit_note_no_unique');
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_refunds');
    }
};

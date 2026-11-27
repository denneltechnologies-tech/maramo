<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_student_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->foreignId('fee_master_id')->constrained('fee_masters')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('fine_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('fine_type', 20)->nullable();
            $table->decimal('fine_rule_amount', 15, 2)->nullable();
            $table->string('fine_rule_frequency', 20)->nullable();
            $table->unsignedInteger('fine_grace_days')->nullable();
            $table->timestamp('fine_accrued_at')->nullable();
            $table->boolean('fine_waived')->default(false);
            $table->string('fine_waived_reason')->nullable();
            $table->string('status', 20)->default('unpaid'); // unpaid | partial | paid
            $table->unsignedTinyInteger('reminder_stage')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['student_id', 'fee_master_id', 'session_id'], 'fee_alloc_unique');
            $table->index(['status', 'due_date'], 'fee_alloc_status_due_idx');
            $table->index(['school_id', 'status', 'due_date'], 'fee_alloc_school_status_due_idx');
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_student_allocations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_discount_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('fee_discount_id')->nullable()->constrained('fee_discounts')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->foreignId('fee_student_allocation_id')->nullable()->constrained('fee_student_allocations')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason', 255)->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('recurrence', 20)->default('one_time');
            $table->foreignId('parent_id')->nullable()->constrained('fee_discount_students')->nullOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('approval_status', 20)->default('approved');
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_remark')->nullable();
            $table->string('status', 20)->default('assigned'); // assigned | used
            $table->timestamps();
            $table->softDeletes();
            $table->index(['school_id', 'approval_status'], 'fee_disc_students_school_status_index');
            $table->index(['student_id', 'recurrence', 'approval_status'], 'fee_disc_students_recurring_index');
            $table->index('school_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_discount_students');
    }
};

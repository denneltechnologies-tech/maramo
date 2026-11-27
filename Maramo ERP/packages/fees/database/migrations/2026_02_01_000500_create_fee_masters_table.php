<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('fee_group_id')->constrained('fee_groups')->cascadeOnDelete();
            $table->foreignId('fee_type_id')->constrained('fee_types')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->unsignedBigInteger('student_category_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('billing_frequency', 20)->default('one_time'); // one_time|monthly|quarterly|half_yearly|annual
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->date('first_due_date')->nullable();
            $table->boolean('is_schedule_parent')->default(false);
            $table->foreignId('parent_fee_master_id')->nullable()->constrained('fee_masters')->nullOnDelete();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('fine_type', 20)->default('none'); // none | fixed | percent
            $table->decimal('fine_amount', 15, 2)->default(0);
            $table->string('fine_frequency', 20)->default('one_time'); // one_time|daily|weekly|monthly
            $table->unsignedInteger('fine_after_days')->default(0);
            $table->foreignId('fee_fine_id')->nullable()->constrained('fee_fines')->nullOnDelete();
            $table->boolean('is_optional')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['session_id', 'status', 'is_schedule_parent'], 'fee_masters_resolver_idx');
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_masters');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month_days')->nullable();
            $table->decimal('working_days', 6, 2)->nullable();
            $table->decimal('payable_days', 6, 2)->nullable();
            $table->decimal('lop_days', 6, 2)->nullable();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('allowance_total', 15, 2)->default(0);
            $table->decimal('deduction_total', 15, 2)->default(0);
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('employer_contribution_total', 15, 2)->default(0);
            $table->foreignId('statutory_rule_set_id')->nullable()->constrained('statutory_rule_sets')->nullOnDelete();
            $table->decimal('net_salary', 15, 2)->default(0);
            $table->string('status')->default('generated');
            $table->string('payment_mode')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('bank_account')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('locked_at')->nullable()->index();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['staff_id', 'month', 'year']);
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};

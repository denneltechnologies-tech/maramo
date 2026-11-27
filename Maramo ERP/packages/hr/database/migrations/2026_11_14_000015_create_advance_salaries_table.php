<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('reason')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('installments')->default(1);
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->unsignedTinyInteger('recovery_start_month')->nullable();
            $table->unsignedSmallInteger('recovery_start_year')->nullable();
            $table->decimal('recovered_amount', 15, 2)->default(0);
            $table->string('recovery_status')->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('staff_id');
            $table->index('status');
            $table->index(['staff_id', 'recovery_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_salaries');
    }
};

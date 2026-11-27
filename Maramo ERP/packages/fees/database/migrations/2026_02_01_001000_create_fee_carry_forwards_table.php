<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_carry_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('from_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->foreignId('fee_student_allocation_id')->nullable()->constrained('fee_student_allocations')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->string('status', 20)->default('open'); // open | collected
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_carry_forwards');
    }
};

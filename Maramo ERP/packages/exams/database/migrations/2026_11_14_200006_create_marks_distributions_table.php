<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marks_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('exam_schedule_id')->constrained('exam_schedules')->cascadeOnDelete();
            $table->string('name'); // Theory / Practical / Assignment …
            $table->decimal('full_marks', 6, 2)->default(0);
            $table->decimal('pass_marks', 6, 2)->nullable();
            $table->decimal('weightage', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
            $table->index('exam_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marks_distributions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_session_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('class_section_id')->nullable()->constrained('class_sections')->nullOnDelete();
            $table->string('roll_no')->nullable();
            $table->string('status')->default('studying'); // studying | promoted | left
            $table->string('result')->nullable(); // pass | fail
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['student_id', 'session_id']);
            $table->index('class_section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_session_records');
    }
};

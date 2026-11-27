<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * timetable_subject_requirements — what the generator must schedule: periods a
 * week of a subject for a class (section_id null = every section of the class,
 * a section row overrides it) and the room type it needs (null = own classroom).
 * Tenant + branch + session scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_subject_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekly_periods')->default(0);
            $table->string('room_type', 50)->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index(['branch_id', 'session_id', 'class_id'], 'tsr_class_idx');
            $table->unique(['branch_id', 'session_id', 'class_id', 'section_id', 'subject_id'], 'tsr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_subject_requirements');
    }
};

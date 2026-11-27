<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * timetable_periods. A single cell
 * of a class-section's weekly routine: a period on a given weekday with its time
 * window, subject, teacher and (optional) room; break rows carry no subject/teacher.
 *
 * Carries school/branch/session directly (not just via the parent timetable) so
 * CONFLICT DETECTION can query across every class-section in a branch/session in a
 * single scoped query. day_of_week is 0=Sunday .. 6=Saturday. Tenant + branch +
 * session scoped, soft-deleting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('timetable_id')->constrained('timetables')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sun .. 6=Sat
            $table->unsignedSmallInteger('period_no')->default(1);
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('room')->nullable();
            $table->boolean('is_break')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index('branch_id');
            $table->index('session_id');
            $table->index('timetable_id');
            // Conflict-detection hot paths.
            $table->index(['branch_id', 'session_id', 'day_of_week', 'teacher_id'], 'tp_teacher_slot_idx');
            $table->index(['timetable_id', 'day_of_week'], 'tp_section_slot_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_periods');
    }
};

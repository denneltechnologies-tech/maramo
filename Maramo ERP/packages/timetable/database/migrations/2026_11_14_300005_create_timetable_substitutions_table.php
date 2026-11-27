<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * timetable_substitutions — the substitution register: on a given date, a
 * timetable period whose teacher is absent, and who covers it. One row per
 * period per date. status: pending (no cover yet) | assigned | cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('timetable_period_id')->constrained('timetable_periods')->cascadeOnDelete();
            $table->foreignId('absent_teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('substitute_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('reason')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['timetable_period_id', 'date']);
            $table->index('school_id');
            $table->index(['branch_id', 'session_id', 'date']);
            $table->index(['substitute_teacher_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_substitutions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('class_section_id')->nullable()->constrained('class_sections')->nullOnDelete();
            $table->string('admission_no');
            $table->string('roll_no')->nullable();
            $table->date('admission_date')->nullable();
            $table->foreignId('student_category_id')->nullable()->constrained('student_categories')->nullOnDelete();
            $table->string('registration_no')->nullable();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('gender')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('religion')->nullable();
            $table->string('caste')->nullable();
            $table->string('mother_tongue')->nullable();
            $table->string('nationality')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('national_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('photo_path')->nullable();

            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('pincode')->nullable();

            $table->string('previous_school_name')->nullable();
            $table->string('previous_class')->nullable();
            $table->text('previous_school_address')->nullable();
            $table->date('training_start_date')->nullable();
            $table->date('completion_date')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('tc_number')->nullable();
            $table->date('tc_date')->nullable();
            $table->boolean('rte')->default(false);
            $table->boolean('physically_challenged')->default(false);
            $table->text('special_needs_note')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->text('note')->nullable();

            // UDISE+ (India) — nullable, ignored elsewhere.
            $table->string('social_category')->nullable();
            $table->string('minority_group')->nullable();
            $table->boolean('is_bpl')->nullable();
            $table->boolean('is_ews')->nullable();
            $table->boolean('is_cwsn')->nullable();
            $table->string('disability_type')->nullable();

            $table->unsignedBigInteger('admission_sibling_id')->nullable();
            $table->string('status')->default('active'); // active | inactive | alumni
            $table->timestamp('disabled_at')->nullable();
            $table->text('disable_note')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('class_section_id');
            $table->unique(['school_id', 'admission_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

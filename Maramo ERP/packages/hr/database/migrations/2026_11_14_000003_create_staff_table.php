<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('employee_id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('role_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('marital_status')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('joining_date');

            $table->string('phone')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('email')->nullable();
            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('qualification')->nullable();
            $table->boolean('is_teaching_staff')->nullable();
            $table->string('academic_qualification')->nullable();
            $table->string('professional_qualification')->nullable();
            $table->string('appointment_type')->nullable();
            $table->string('classes_taught')->nullable();
            $table->string('subjects_taught')->nullable();
            $table->boolean('trained_in_computer')->nullable();
            $table->unsignedSmallInteger('in_service_training_days')->nullable();
            $table->string('staff_disability_type')->nullable();
            $table->text('work_experience')->nullable();
            $table->text('note')->nullable();

            $table->string('epf_no')->nullable();
            $table->string('esi_no')->nullable();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->string('contract_type')->nullable();
            $table->string('work_shift')->nullable();
            $table->string('work_location')->nullable();
            $table->string('salary_grade')->nullable();
            $table->json('allowances')->nullable();
            $table->json('deductions')->nullable();

            $table->string('account_title')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('tax_id')->nullable();

            $table->string('facebook_url')->nullable();
            $table->string('twitter_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('instagram_url')->nullable();

            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'employee_id']);
            $table->index('branch_id');
            $table->index('department_id');
            $table->index('designation_id');
            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};

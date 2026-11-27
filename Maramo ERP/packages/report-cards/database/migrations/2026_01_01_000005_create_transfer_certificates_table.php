<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->string('tc_number');
            $table->date('date_of_leaving')->nullable();
            $table->string('reason_for_leaving')->nullable();
            $table->string('conduct')->nullable();
            $table->text('remarks')->nullable();
            $table->string('last_class_attended')->nullable();
            $table->unsignedInteger('total_working_days')->nullable();
            $table->unsignedInteger('days_present')->nullable();
            $table->boolean('dues_cleared')->default(false);
            $table->string('promotion_status')->nullable();
            $table->string('template')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'tc_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_certificates');
    }
};
